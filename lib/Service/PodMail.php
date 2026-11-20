<?php

declare(strict_types=1);

namespace OCA\UserPods\Service;

use OCP\IConfig;
use OCP\Util;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;

/**
 * Mail sent by an application in a pod (e.g. Overleaf's invitations and
 * password links), relayed through this server's own mail settings - the pod
 * gets no mail credentials (its owner has root there).
 *
 * The pod hands over a complete message plus its recipients. Here the sender
 * becomes this server's no-reply address (the message's display name is kept,
 * sanitized), whatever the pod wrote; Bcc/Sender/Return-Path headers are
 * dropped; size, recipients and rate are limited per owner - the rate scaled
 * by how many accounts may log in to the pod (its groups' members: a Moodle
 * notifies every student of a course); every message is logged with its
 * owner, pod, recipients and subject.
 */
class PodMail {
	public const MAX_BYTES = 2 * 1024 * 1024;
	public const MAX_RECIPIENTS = 10;
	public const PER_HOUR = 20;   // at least; or 2 per account that may log in
	public const PER_DAY = 100;   // at least; or 10 per account that may log in
	private const PREF = 'mail_sent'; // user value: JSON {hour (unix time / 3600): messages}, last 24 h

	public function __construct(
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string[] $to
	 * @throws \InvalidArgumentException a bad message or recipient
	 * @throws \OverflowException over the rate limit
	 */
	public function relay(string $uid, string $pod, array $to, string $raw, int $audience = 1): void {
		$to = array_values(array_unique(array_map(static fn ($a) => trim((string)$a), $to)));
		if ($to === [] || count($to) > self::MAX_RECIPIENTS) {
			throw new \InvalidArgumentException('1 to ' . self::MAX_RECIPIENTS . ' recipients per message');
		}
		foreach ($to as $addr) {
			if (filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
				throw new \InvalidArgumentException('Invalid recipient: ' . $addr);
			}
		}
		if ($raw === '' || strlen($raw) > self::MAX_BYTES) {
			throw new \InvalidArgumentException('Messages are limited to ' . (self::MAX_BYTES >> 20) . ' MB');
		}
		$from = Util::getDefaultEmailAddress('no-reply');
		[$message, $subject] = $this->withSender($raw, $from);
		$this->countSend($uid, $audience);
		$this->transport()->send(new RawMessage($message), new Envelope(new Address($from), array_map(static fn ($a) => new Address($a), $to)));
		$this->logger->info("user_pods: mail from container $pod of $uid to " . implode(', ', $to) . ": $subject", ['app' => 'user_pods']);
	}

	/** Note a send now, or refuse when over the hourly or daily limit. */
	private function countSend(string $uid, int $audience): void {
		$perHour = max(self::PER_HOUR, 2 * $audience);
		$perDay = max(self::PER_DAY, 10 * $audience);
		$hour = intdiv(time(), 3600);
		$counts = json_decode($this->config->getUserValue($uid, 'user_pods', self::PREF, '{}'), true);
		$counts = is_array($counts) && !array_is_list($counts) ? $counts : []; // (was a list of times)
		$counts = array_filter($counts, static fn ($n, $h) => (int)$h > $hour - 24, ARRAY_FILTER_USE_BOTH);
		if (($counts[$hour] ?? 0) >= $perHour || array_sum($counts) >= $perDay) {
			$this->logger->warning("user_pods: mail from a container of $uid refused: over the limit", ['app' => 'user_pods']);
			throw new \OverflowException("Your containers may send $perHour messages per hour and $perDay per day.");
		}
		$counts[$hour] = ($counts[$hour] ?? 0) + 1;
		$this->config->setUserValue($uid, 'user_pods', self::PREF, json_encode((object)$counts));
	}

	/**
	 * The message with $from as its only sender: From replaced (display name
	 * kept, sanitized), Sender/Return-Path/Bcc dropped. Returns [message, subject].
	 */
	private function withSender(string $raw, string $from): array {
		$parts = preg_split('/\r?\n\r?\n/', $raw, 2);
		if (count($parts) !== 2) {
			throw new \InvalidArgumentException('Not a mail message (no header)');
		}
		[$head, $body] = $parts;
		$lines = preg_split('/\r?\n(?![ \t])/', $head); // one entry per (unfolded) header
		$name = '';
		$subject = '';
		$keep = [];
		foreach ($lines as $line) {
			if (!preg_match('/^([!-9;-~]+):/', $line, $m)) {
				throw new \InvalidArgumentException('Not a mail message (bad header)');
			}
			$field = strtolower($m[1]);
			if ($field === 'from') {
				if (preg_match('/^From:\s*"?([^"<]*?)"?\s*</i', preg_replace('/\r?\n[ \t]+/', ' ', $line), $n)) {
					$name = $n[1];
				}
				continue;
			}
			if (in_array($field, ['sender', 'return-path', 'bcc'], true)) {
				continue;
			}
			if ($field === 'subject') {
				$subject = trim(iconv_mime_decode(substr($line, 8), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: '');
			}
			$keep[] = $line;
		}
		$name = trim(mb_substr((string)preg_replace('/[^\p{L}\p{N} .\-()]/u', '', iconv_mime_decode($name, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: ''), 0, 60));
		$fromHeader = 'From: ' . ($name !== '' ? '=?UTF-8?B?' . base64_encode($name) . '?= ' : '') . '<' . $from . '>';
		array_unshift($keep, $fromHeader);
		return [implode("\r\n", $keep) . "\r\n\r\n" . $body, $subject];
	}

	/** SMTP with this server's mail settings (as Nextcloud's own mailer builds it). */
	private function transport(): EsmtpTransport {
		if ($this->config->getSystemValueString('mail_smtpmode', 'smtp') !== 'smtp') {
			throw new \RuntimeException('This server sends no mail by SMTP');
		}
		$transport = new EsmtpTransport(
			$this->config->getSystemValueString('mail_smtphost', '127.0.0.1'),
			$this->config->getSystemValueInt('mail_smtpport', 25),
			$this->config->getSystemValue('mail_smtpsecure', null) === 'ssl' ? true : null,
			null,
			$this->logger,
		);
		$transport->getStream()->setTimeout($this->config->getSystemValueInt('mail_smtptimeout', 10));
		if ($this->config->getSystemValueBool('mail_smtpauth', false)) {
			$transport->setUsername($this->config->getSystemValueString('mail_smtpname', ''));
			$transport->setPassword($this->config->getSystemValueString('mail_smtppassword', ''));
		}
		$options = $this->config->getSystemValue('mail_smtpstreamoptions', []);
		if (is_array($options) && $options !== []) {
			$stream = $transport->getStream();
			$stream->setStreamOptions(array_merge_recursive($stream->getStreamOptions(), $options));
		}
		$host = parse_url($this->config->getSystemValueString('overwrite.cli.url', ''), PHP_URL_HOST);
		if (!empty($host)) {
			$transport->setLocalDomain($host);
		}
		return $transport;
	}
}
