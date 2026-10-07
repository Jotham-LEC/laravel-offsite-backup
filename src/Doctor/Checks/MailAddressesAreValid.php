<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;

/**
 * spatie validates the notification addresses whenever it reads its config, mail notifications
 * on or off: a placeholder such as MAIL_FROM_ADDRESS=hello@{{DOMAIN}} makes every backup:run,
 * backup:clean and backup:monitor fail before doing anything.
 */
class MailAddressesAreValid implements Check
{
    private const PLACEHOLDERS = ['your@example.com', 'hello@example.com'];

    public function name(): string
    {
        return 'Mail addresses';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $mailOn = $this->mailNotificationsOn();
        $from = config('backup.notifications.mail.from.address') ?? config('mail.from.address');
        $to = config('backup.notifications.mail.to');
        $results = [];

        if (! is_string($from) || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $results[] = CheckResult::fail(
                'The mail sender '.(is_string($from) && $from !== '' ? "'{$from}'" : '(empty)').' isn\'t a valid address; spatie refuses its config, so backup:run fails before it starts.',
                'Set MAIL_FROM_ADDRESS to a real address (or backup.notifications.mail.from.address).',
            );
        }

        foreach (is_array($to) ? $to : [$to] as $address) {
            if (! is_string($address) || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $results[] = CheckResult::fail(
                    'The notification address '.(is_string($address) && $address !== '' ? "'{$address}'" : '(empty)').' isn\'t a valid address; spatie refuses its config.',
                    'Set BACKUP_NOTIFY_EMAIL (backup.notifications.mail.to).',
                );
            } elseif ($mailOn && in_array(strtolower($address), self::PLACEHOLDERS, true)) {
                $results[] = CheckResult::warn("Failure mail goes to the placeholder {$address}.", 'Set BACKUP_NOTIFY_EMAIL.');
            }
        }

        if ($mailOn && is_string($from) && in_array(strtolower($from), self::PLACEHOLDERS, true)) {
            $results[] = CheckResult::warn("Mail is sent from the placeholder {$from}; many mail servers reject it.", 'Set MAIL_FROM_ADDRESS.');
        }

        $summary = $mailOn
            ? sprintf('Mail notifications from %s to %s.', is_string($from) ? $from : '?', implode(', ', array_map(fn ($a): string => is_string($a) ? $a : '?', is_array($to) ? $to : [$to])))
            : 'Mail notifications are off; the addresses are valid.';

        return CheckResult::combine($results, $summary);
    }

    private function mailNotificationsOn(): bool
    {
        foreach ((array) config('backup.notifications.notifications', []) as $channels) {
            if (in_array('mail', (array) $channels, true)) {
                return true;
            }
        }

        return false;
    }
}
