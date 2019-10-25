<?php

namespace Solcre\SolcreFramework2\Service;

use Exception;
use MegaPharma\V1\Domain\Common\Interfaces\TemplateInterface;
use MegaPharma\V1\Domain\Common\Utility\Email;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;
use Solcre\SolcreFramework2\Entity\EmailAddress;
use Solcre\SolcreFramework2\Entity\ScheduleEmail;

class EmailService
{
    public const TYPE_FROM = 1;
    public const TYPE_TO = 2;
    public const TYPE_CC = 3;
    public const TYPE_BCC = 4;
    public const TYPE_REPLAY_TO = 5;
    protected $configuration;
    protected $scheduleEmailService;
    protected $templateService;
    protected $logger;
    private $mailer;

    public function __construct(PHPMailer $mailer, $configuration, ScheduleEmailService $scheduleEmailService, TemplateInterface $templateService, LoggerInterface $logger)
    {
        $this->mailer = $mailer;
        $this->configuration = $configuration;
        $this->scheduleEmailService = $scheduleEmailService;
        $this->templateService = $templateService;
        $this->logger = $logger;
    }

    public function sendTpl(
        array $vars,
        $templateName,
        array $addresses,
        string $subject,
        $charset = 'UTF-8',
        $altText = '',
        $from = null
    ): bool {
        try {
            $from = $this->getFromEmail($from);
            $addresses = $this->generateAddresses($addresses);
            if (empty($addresses)) {
                throw new Exception('Addresses must not be empty', 422);
            }
            $content = $this->getRenderTemplate($vars, $templateName);
            return $this->sendOrSaveEmail($from, $addresses, $content, $charset, $subject, $altText);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), ['EMAIl-SERVICE']);
            unset($e);
            return false;
        }
    }

    public function getFromEmail($from = null): EmailAddress
    {
        if (empty($from) || ! Email::validateEmail($from)) {
            $from = $this->configuration['MegaPharma']['EMAILS']['DEFAULT_FROM_EMAIL'];
        }

        return new EmailAddress($from, null, self::TYPE_FROM);
    }

    public function generateAddresses(array $addresses): array
    {
        $emailAddresses = [];
        foreach ($addresses as $address) {
            if (\is_array($address)) {
                $email = $address['email'] ?? null;
                $type = $address['type'] ?? null;
                $name = $address['name'] ?? null;
                if (Email::validateEmail($email) && $this->validateEmailType($type)) {
                    $emailAddresses[] = new EmailAddress($email, $name, $type);
                }
            }
        }
        return $emailAddresses;
    }

    private function validateEmailType(int $type): bool
    {
        $types = [
            self::TYPE_TO,
            self::TYPE_CC,
            self::TYPE_BCC,
            self::TYPE_REPLAY_TO
        ];
        return \in_array($type, $types, true);
    }

    private function getRenderTemplate(array $data, string $templatePath): string
    {
        return $this->templateService->render($templatePath, $this->mergeDefaultVariables($data));
    }

    private function mergeDefaultVariables(array $data = []): array
    {
        $defaultVariables = $this->getDefaultVariables();
        if (! empty($data)) {
            return \array_merge($defaultVariables, $data);
        }
        return $defaultVariables;
    }

    private function getDefaultVariables(): array
    {
        return [
            'images_path' => $this->getEmailAssetsPath(),
        ];
    }

    private function getEmailAssetsPath(): string
    {
        return $this->configuration['MegaPharma']['EMAILS']['ASSETS_PATH'];
    }

    private function sendOrSaveEmail(EmailAddress $from, array $addresses, string $content, string $charset, string $subject, $altText = ''): ?bool
    {
        try {
            $isSaved = $this->saveEmail($from, $addresses, $subject, $content, $altText, $charset);
            if (! $isSaved) {
                return $this->send($from, $addresses, $subject, $content, $altText, $charset);
            }
            return $isSaved;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), ['EMAIl-SERVICE']);
            unset($e);
            return $this->send($from, $addresses, $subject, $content, $altText, $charset);
        }
    }

    private function saveEmail(EmailAddress $from, $addresses, $subject, $content, $altText, $charset): bool
    {
        $data['from'] = [
            'email' => $from->getEmail(),
            'type'  => $from->getType()
        ];
        $data['content'] = $content;
        $data['charset'] = $charset;
        $data['subject'] = $subject;
        $data['altText'] = $altText ?? 'To view the message, please use an HTML compatible email viewer!';

        foreach ($addresses as $address) {
            if ($address instanceof EmailAddress) {
                switch ($address->getType()) {
                    case self::TYPE_CC:
                        $data['addresses'][] = [
                            'name'  => $address->getName(),
                            'email' => $address->getEmail(),
                            'type'  => self::TYPE_CC
                        ];
                        break;
                    case self::TYPE_BCC:
                        $data['addresses'][] = [
                            'name'  => $address->getName(),
                            'email' => $address->getEmail(),
                            'type'  => self::TYPE_BCC
                        ];
                        break;
                    case self::TYPE_REPLAY_TO:
                        $data['addresses'][] = [
                            'name'  => $address->getName(),
                            'email' => $address->getEmail(),
                            'type'  => self::TYPE_REPLAY_TO
                        ];
                        break;
                    case self::TYPE_TO:
                    default:
                        $data['addresses'][] = [
                            'name'  => $address->getName(),
                            'email' => $address->getEmail(),
                            'type'  => self::TYPE_TO
                        ];
                        break;
                }
            }
        }

        $scheduleEntity = $this->scheduleEmailService->add($data);
        return $scheduleEntity instanceof ScheduleEmail;
    }

    public function send(EmailAddress $from, array $addresses, string $subject, string $content, string $charset, $altText = ''): ?bool
    {
        try {
            $this->mailer->CharSet = $charset;
            $this->mailer->setFrom($from->getEmail(), $from->getName());
            foreach ($addresses as $address) {
                switch ($address->getType()) {
                    case self::TYPE_CC:
                        $this->mailer->addCC($address->getEmail(), $address->getName());
                        break;
                    case self::TYPE_BCC:
                        $this->mailer->addBCC($address->getEmail(), $address->getName());
                        break;
                    case self::TYPE_REPLAY_TO:
                        $this->mailer->addReplyTo($address->getEmail(), $address->getName());
                        break;
                    case self::TYPE_TO:
                    default:
                        $this->mailer->addAddress($address->getEmail(), $address->getName());
                        break;
                }
            }
            $this->mailer->Subject = $subject;
            $this->mailer->AltBody = $altText ?? 'To view the message, please use an HTML compatible email viewer!';
            $this->mailer->msgHTML($content);
            $this->mailer->isSMTP();
            $this->mailer->SMTPAuth = true;
            $this->mailer->Host = $this->configuration['MegaPharma']['EMAILS']['SMTP_CREDENTIALS']['HOST'];
            $this->mailer->Username = $this->configuration['MegaPharma']['EMAILS']['SMTP_CREDENTIALS']['USERNAME'];
            $this->mailer->Password = $this->configuration['MegaPharma']['EMAILS']['SMTP_CREDENTIALS']['PASSWORD'];
            $this->mailer->Port = $this->configuration['MegaPharma']['EMAILS']['SMTP_CREDENTIALS']['PORT'];
            if (! $this->mailer->send()) {
                throw new \Exception($this->mailer->ErrorInfo, 400);
            }
            $this->mailer->clearAddresses();
            return true;
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }
}
