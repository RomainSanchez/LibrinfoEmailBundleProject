<?php

namespace Librinfo\EmailBundle\SwiftMailer\Spool;

use Doctrine\ORM\EntityManager;
use Librinfo\EmailBundle\SwiftMailer\Spool\SpoolStatus;
use Librinfo\EmailBundle\SwiftMailer\DecoratorPlugin\Replacements;
use Librinfo\EmailBundle\SwiftMailer\Tracking;

/**
 * Class DbSpool
 */
class DbSpool extends \Swift_ConfigurableSpool
{

    /**
     * @var EntityManager
     */
    private $manager;

    /**
     * @var string
     */
    private $environment;

    /**
     * @var EntityRepository
     */
    private $repository;
    private $pauseTime;

    /**
     * @param EntityManager $manager
     * @param string $environment
     */
    public function __construct(EntityManager $manager, $environment)
    {
        $this->manager = $manager;
        $this->environment = $environment;
        $this->repository = $this->manager->getRepository('LibrinfoEmailBundle:Email');
    }

    /**
     * Starts this Spool mechanism.
     */
    public function start()
    {
        
    }

    /**
     * Stops this Spool mechanism.
     */
    public function stop()
    {
        
    }

    /**
     * Tests if this Spool mechanism has started.
     *
     * @return boolean
     */
    public function isStarted()
    {
        return true;
    }

    /**
     * Queues a message.
     *
     * @param \Swift_Mime_Message $message The message to store
     * @return boolean Whether the operation has succeeded
     * @throws \Swift_IoException if the persist fails
     */
    public function queueMessage(\Swift_Mime_Message $message)
    {
        $email = $this->repository->findOneBy(array("messageId" => $message->getId()));
        $email->setMessage(base64_encode(serialize($message)));
        $email->setStatus(SpoolStatus::STATUS_READY);
        $email->setEnvironment($this->environment);
        $this->updateEmail($email);

        return true;
    }

    /**
     * Sends messages using the given transport instance.
     *
     * @param \Swift_Transport $transport         A transport instance
     * @param string[]        &$failedRecipients An array of failures by-reference
     *
     * @return int The number of sent emails
     */
    public function flushQueue(\Swift_Transport $transport, &$failedRecipients = null)
    {
        if (!$transport->isStarted())
        {
            $transport->start();
        }

        $emails = $this->repository->findBy(
                array("status" => SpoolStatus::STATUS_READY, "environment" => $this->environment), null
        );

        if (!count($emails))
        {
            return 0;
        }

        $failedRecipients = (array) $failedRecipients;
        $count = 0;
        $time = time();

        foreach ($emails as $email)
        {
            $email->setStatus(SpoolStatus::STATUS_PROCESSING);

            $this->updateEmail($email);

            $message = unserialize(base64_decode($email->getMessage()));

            $replacements = new Replacements($this->manager);
            $decorator = new \Swift_Plugins_DecoratorPlugin($replacements);
            $transport->registerPlugin($decorator);

            $addresses = explode(';', $email->getFieldTo());

            foreach ($addresses as $address)
            {
                $message->setTo($address);

                if ($email->getTracking())
                {
                    $tracker = new Tracking();

                    $content = $tracker->addTracking($email->getContent(), $address, $email->getId());

                    $message->setBody($content);
                }

                try {
                    $count += $transport->send($message, $failedRecipients);
                    sleep($this->pauseTime);
                } catch (\Swift_TransportException $e) {
                    $email->setStatus(SpoolStatus::STATUS_READY);
                    $this->updateEmail($email);
                }
            }
            $email->setStatus(SpoolStatus::STATUS_COMPLETE);

            $this->updateEmail($email);

            if ($this->getTimeLimit() && (time() - $time) >= $this->getTimeLimit())
            {
                break;
            }
        }
        return $count;
    }

    public function updateEmail($email)
    {
        $this->manager->persist($email);
        $this->manager->flush();
    }

    public function setPauseTime($pauseTime)
    {

        $this->pauseTime = $pauseTime;
    }

}
