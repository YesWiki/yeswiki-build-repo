<?php
namespace YesWikiRepo;

use \Exception;
use GuzzleHttp\Client;
use ThibaudDauce\Mattermost\Attachment;
use ThibaudDauce\Mattermost\Mattermost;
use ThibaudDauce\Mattermost\Message;

abstract class Controller
{
    const ATTACHMENTS_PER_MESSAGE = 15;
    const LOG_LINES = 20;
    const LOG_CHARS = 2500;

    protected $repo;

    public function __construct($repo)
    {
        $this->repo = $repo;
    }

    abstract public function run($params);

    /** Send a Mattermost notification with one attachment per package the build looked at. */
    protected function sendMattermostNotification(array $results, string $title = ''): void
    {
        if (empty($this->repo->localConf['mattermost-hook-url']) || empty($results)) {
            return;
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $skipCount = count(array_filter($results, fn($r) => !empty($r['skipped'])));
        $failCount = count($results) - $successCount - $skipCount;

        $summary = $failCount > 0
            ? "Build terminé avec {$failCount} erreur(s) sur " . count($results) . " paquet(s)"
            : "Build réussi : {$successCount} paquet(s) mis à jour";
        if ($skipCount > 0) {
            $summary .= ", {$skipCount} paquet(s) ignoré(s) faute de version conforme";
        }
        if ($title !== '') {
            $summary = $title . "\n" . $summary;
        }

        $batches = array_chunk($results, self::ATTACHMENTS_PER_MESSAGE);
        foreach ($batches as $index => $batch) {
            $text = $summary;
            if (count($batches) > 1) {
                $text .= ' (' . ($index + 1) . '/' . count($batches) . ')';
            }
            $message = $this->newMessage($text);
            foreach ($batch as $result) {
                $message = $message->attachment(fn(Attachment $attachment) => $this->fillAttachment($attachment, $result));
            }
            $this->post($message);
        }
    }

    /** Send a one-line notification, for the hooks that end up building nothing. */
    protected function sendPlainNotification(string $text): void
    {
        if (empty($this->repo->localConf['mattermost-hook-url'])) {
            return;
        }

        $this->post($this->newMessage($text));
    }

    private function newMessage(string $text): Message
    {
        return (new Message())
            ->text($text)
            ->channel($this->repo->localConf['mattermost-channel'])
            ->username($this->repo->localConf['mattermost-authorName'])
            ->iconUrl($this->repo->localConf['mattermost-authorIcon']);
    }

    /**
     * A failed notification must not fail the build that produced it, nor answer 500 to GitHub.
     */
    private function post(Message $message): void
    {
        try {
            (new Mattermost(new Client(), $this->repo->localConf['mattermost-hook-url']))->send($message);
        } catch (\Throwable $throwable) {
            syslog(LOG_ERR, 'Mattermost notification failed : ' . $throwable->getMessage());
        }
    }

    private function fillAttachment(Attachment $attachment, array $result): void
    {
        $actionDesc = $this->buildActionDescription($result);
        $attachment
            ->fallback($actionDesc)
            ->authorName($this->repo->localConf['mattermost-authorName'])
            ->authorIcon($this->repo->localConf['mattermost-authorIcon'])
            ->authorLink($this->repo->localConf['repo-url'])
            ->title($actionDesc);

        if ($result['success']) {
            $attachment->success()->field('Status', ':white_check_mark: Success', true);
        } elseif (!empty($result['skipped'])) {
            $attachment->info()->field('Status', ':warning: Skipped', true);
        } else {
            $attachment->error()->field('Status', ':x: Failed', true);
        }

        if (!empty($result['channel'])) {
            $attachment->field('Canal', $result['channel'], true);
        }
        $attachment->field('Version', $this->describeVersionChange($result), true);
        if (($result['elapsed'] ?? null) !== null) {
            $attachment->field('Elapsed', "{$result['elapsed']}s", true);
        }
        if (!empty($result['url'])) {
            $attachment->field('Archive', $result['url'], false);
        }
        $attachment->field('Log', $this->formatLog($result['log'] ?? []), false);
    }

    /** What moved, so a notification says more than the number it ends on. */
    private function describeVersionChange(array $result): string
    {
        $version = $result['version'] ?: '-';
        $previous = $result['previousVersion'] ?? '';

        if ($previous === '' || ltrim($previous, 'v') === ltrim((string) $result['version'], 'v')) {
            return $version;
        }

        return $previous . ' → ' . $version;
    }

    /**
     * The tail of the log, capped. Mattermost rejects an oversized payload and the whole
     * notification is lost with it, which is exactly what a failed composer used to do.
     */
    private function formatLog(array $log): string
    {
        $lines = $log;
        $cut = false;

        if (count($lines) > self::LOG_LINES) {
            $lines = array_slice($lines, -self::LOG_LINES);
            $cut = true;
        }

        $text = implode("\n", $lines);
        if (strlen($text) > self::LOG_CHARS) {
            $text = substr($text, -self::LOG_CHARS);
            $cut = true;
        }
        if ($cut) {
            $text = "[...] sortie complète dans syslog\n" . $text;
        }

        return "```\n{$text}\n```";
    }

    private function buildActionDescription(array $result): string
    {
        $type = $result['type'];
        $name = $result['shortName'];

        if (!empty($result['skipped'])) {
            return "Skipped {$type} **{$name}** : {$result['error']}";
        }
        if (!empty($result['tag']) && $result['tag'] === 'latest') {
            return "Build {$type} **{$name}** with tag **{$result['version']}**";
        }
        if (!empty($result['branch'])) {
            return "Build {$type} **{$name}** from latest branch **{$result['branch']}**";
        }
        if (!empty($result['tag'])) {
            return "Build {$type} **{$name}** with tag **{$result['tag']}**";
        }
        return "Build {$type} **{$name}**";
    }
}
