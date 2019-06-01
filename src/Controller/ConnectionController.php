<?php

namespace App\Controller;


use App\Api\FileOperator\FileOperator;
use App\Api\Tilde\Connector;
use App\Api\Tilde\CtmLine;
use App\Api\Tilde\CtmModel;
use App\Api\Tilde\RequestModel;
use App\Api\Tilde\ResponseModel;
use App\Api\Tilde\SummaryModel;
use App\Entity\CreditLogActions;
use App\Entity\File;
use App\Entity\User;
use App\Entity\UserCreditLog;
use App\Entity\UserFile;
use App\Form\FileUploadManager;
use App\Repository\WordBlockGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ConnectionController extends AbstractController
{
    const ERROR_NO_CREDITS = 'no_credits';

//    /**
//     * @Route("/", name="connection")
//     */
    public function showStaticFileUploadPage()
    {
        $message = '';

//        $output .= $package->getUrl('/demo_record.m4a');
        return $this->render('home/uploadDemoFile.html.twig', [
            'soundFile' => 'demo_record.m4a',
            'message' => $message
        ]);
    }

    public function sendDemoFile(Request $request)
    {

        $file_name = $request->request->get('upload_demo_file');
        if (!empty($file_name)) {
            $file_operator = new FileOperator();
            $file_path = $file_operator->uploadFileToServer($_ENV['AUDIO_FILES_DEMO_DIR'] . $file_name, false);
            if ($file_path && file_exists($file_path)) {
                $connector = new Connector();
                $request = new RequestModel($file_path);
                /** @var ResponseModel $response */
                $response = $connector->sendFile($request);

                if ($response) {
                    return $this->forward('App\Controller\ConnectionController::refreshStatus', [
                        'jobId' => $response->getRequestId()
                    ]);
                }
            }
        }

        $message = 'No file found!';
        return $this->render('home/uploadDemoFile.html.twig', [
            'soundFile' => 'demo_record.m4a',
            'message' => $message
        ]);

    }

    public function sendFileToScrybe(File $file)
    {
        $connector = new Connector();
        $fileOperator = new FileUploadManager(null, null);
        $request = new RequestModel($fileOperator->getBasePath() . $_ENV['AUDIO_FILES_UPLOAD_DIR'] . $file->getFileDir() . $file->getFileName());
        $response = $connector->sendFile($request);

        return $response;
    }

    public function refreshStatus(string $userfileId)
    {
        /** @var UserFile $userFile */
        $userFile = $this->getDoctrine()->getRepository(UserFile::class)->find($userfileId);
        $originalFile = $userFile->getUserfileFileId();

        $connector = new Connector();
        /** @var ResponseModel $response */
        $response = $connector->checkJobStatus($originalFile->getFileJobId());

        if ($response->getResponseStatus() == ResponseModel::SUCCESS) {
//            $this->showResults($jobId);
            //saugome

            return $this->forward('App\Controller\ConnectionController::showResults', [
                'userfileId' => $userfileId,
                'redirected' => true,
            ]);
        }

        return $this->render('home/checkScrybeStatus.html.twig', [
            'userfileId' => $userfileId,
            'job_status' => $response->getResponseStatusText(),
            'fileName' => $userFile->getUserfileTitle()
        ]);
    }

    public function showResults(string $userfileId, bool $redirected = false)
    {
        $entityManager = $this->getDoctrine()->getManager();

        /** @var UserFile $userFile */
        $userFile = $this->getDoctrine()->getRepository(UserFile::class)->find($userfileId);
        $originalFile = $userFile->getUserfileFileId();
        $connector = new Connector();
        if ($redirected) { //reiskia, kad katik baige transkcibcijas, saugome pirmiausia rezultatus
            $summary = $connector->getJobSummary($originalFile->getFileJobId());
            $text = $connector->getScrybedTxt($originalFile->getFileJobId());
            $ctm = $connector->getScrybedCtm($originalFile->getFileJobId());
            if (empty($originalFile->getFileDefaultCtm())) {
                $originalFile->setFileDefaultCtm($ctm->getRawCtm());
                $originalFile->setFileTxt($text);
                $originalFile->setFileWords($summary->getWords());
                $originalFile->setFileConfidence($summary->getConfidence());
                $entityManager->persist($originalFile);
            }
            if (empty($userFile->getUserfileCtm())) {
                $userFile->setUserfileCtm($ctm->getRawCtm());
                $userFile->setUserfileUpdated(new \DateTime());
                $userFile->setUserfileIsScrybed(1);
                $entityManager->persist($userFile);
            }
            $lengthRounded = $summary->getLengthRounded();
            $confidence = $summary->getConfidence();
            $words = $summary->getWords();

            $entityManager->flush();

            $this->saveCreditLog($originalFile->getFileLength(), true, $userFile);
        }
        else {
            $lengthRounded = $originalFile->getFileLength();
            $confidence = $originalFile->getFileConfidence();
            $words = $originalFile->getFileWords();
            $text = $connector->getScrybedTxt($originalFile->getFileJobId());
            $ctm = $connector->getScrybedCtm($originalFile->getFileJobId());
        }


        return $this->render('home/showScrybedText.html.twig', [
            'userfileId' => $userfileId,
            'fileName' => $userFile->getUserfileTitle(),
            'length' => $lengthRounded,
            'confidence' => $confidence,
            'words' => $words,
            'text' => $text,
            'ctm' => $ctm,
        ]);

    }

    public function DownloadTxtFile(string $jobId)
    {
        $connector = new Connector();
        $text = $connector->getScrybedTxt($jobId);

        $response = new Response($text);

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'scrybed_text.txt'
        );

        $response->headers->set('Content-Disposition', $disposition);
        return $response;
    }

    public function processScrybeFile(string $userfileId, EntityManagerInterface $entityManager)
    {
        /**
         *  1. pasigetiname faila
         * 2. patikriname ar jis jau turi vertima. jeigu taip, tuomet ji uzsetinkime vartotojo vertimui, Done.
         * 3. jeigu neturi vertimo - siuskime ji i API
         * 4. sumazinkime kreditus.
         */

        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        /** @var User $user */
        $user = $this->getUser();

        /** @var UserFile $userFile */
        $userFile = $entityManager->getRepository(UserFile::class)->find($userfileId);
        $originalFile = $userFile->getUserfileFileId();

        if ($user->getCredits() < $originalFile->getFileLength()) {
            return self::ERROR_NO_CREDITS;
        }

        if ($userFile->getUserfileIsScrybed()) {
            //jau vis tik buvo useris scrybe padares
            return $this->forward('App\Controller\ConnectionController::showResults', [
                'userfileId' => $userfileId
            ]);

        } elseif (!empty($originalFile->getFileDefaultCtm())) {
            //darome scrybe. bet kadangi origin turi vertima, tuomet ji tik perkeliame
            $userFile->setUserfileCtm($originalFile->getFileDefaultCtm());
            $userFile->setUserfileText($this->makeCtmJson($originalFile));
            $userFile->setUserfileUpdated(new \DateTime());
            $userFile->setUserfileIsScrybed(1);

            $this->saveCreditLog($originalFile->getFileLength(), true, $userFile);

            $entityManager->flush();
        } else {
            $result = $this->sendFileToScrybe($originalFile);

            $originalFile->setFileJobId($result->getRequestId());
            $entityManager->flush();

        }

        return $this->redirectToRoute('check_scrybe_status', ['userfileId' => $userfileId]);
    }

    public function saveCreditLog(int $credits, bool $payOut = true, UserFile $userFile = null)
    {
        $entityManager = $this->getDoctrine()->getManager();
        $user = $this->getUser();

        $actionName = $payOut ? 'Scrybe_file' : 'Top_up_credits';
        $logAction = $entityManager->getRepository(CreditLogActions::class)->findOneBy(['claName' => $actionName]);

        $operationLog = new UserCreditLog();
        $operationLog->setUclCreated(new \DateTime());
        $operationLog->setUclCredits($credits * ($payOut ? -1 : 1));
        $operationLog->setUclUserfileId($userFile);
        $operationLog->setUclUserId($user);
        $operationLog->setUclActionId($logAction);

        $entityManager->persist($operationLog);
        $entityManager->flush();
    }

    private function makeCtmJson(File $file) : array
    {
        $ctm = new CtmModel($file->getFileDefaultCtm());
        $text = $file->getFileTxt();
        $words = explode(' ', $text);
        $wordsCount = $file->getFileWords();

        if(count($words)!==$wordsCount)
            return new \Exception("Unable to format JSON from CTM source", 1);

        $jsonObject = [];

        $index = 0;
        /** @var CtmLine $_ctm */
        foreach ($ctm->getCtm() as $_ctm) {
            $jsonLine = new \stdClass();
            $jsonLine->duration = $_ctm->getDuration();
            $jsonLine->beginTime = $_ctm->getBeginTime();
            $jsonLine->confidence = $_ctm->getConfidence();
            $jsonLine->word = $words[$index];//$_ctm->getWordId();

            array_push($jsonObject, $jsonLine);

            $index++;
        }

        return $jsonObject;
    }
}

