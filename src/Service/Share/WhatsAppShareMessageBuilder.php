<?php

declare(strict_types=1);

namespace App\Service\Share;

use App\Entity\Note;

final class WhatsAppShareMessageBuilder
{
    private const EXCERPT_LENGTH = 200;

    public function buildMessage(Note $note, string $shareUrl): string
    {
        $label = $note->getReportNumber() !== null
            ? sprintf('Report %d — %s', $note->getReportNumber(), $this->displayTitle($note))
            : $note->getTitle();

        return sprintf(
            'New session report: %s' . "\n" . '%s' . "\n" . '%s',
            $label,
            $this->excerpt($note),
            $shareUrl
        );
    }

    public function buildShareUrl(string $message): string
    {
        return 'https://wa.me/?text=' . rawurlencode($message);
    }

    public function excerptFromText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        if ($normalized === '') {
            return '';
        }

        if (mb_strlen($normalized) <= self::EXCERPT_LENGTH) {
            return $normalized;
        }

        return mb_substr($normalized, 0, self::EXCERPT_LENGTH) . '…';
    }

    private function excerpt(Note $note): string
    {
        $plain = trim(strip_tags($note->getHtml()));
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->excerptFromText($plain);
    }

    private function displayTitle(Note $note): string
    {
        $title = $note->getTitle();
        $filenameStyle = preg_match('/^Report-\d+\s+/u', $title) === 1;

        if ($filenameStyle && preg_match('/^Report-\d+\s+(?:\d{1,2}\.\d{1,2}\.\d{3,4}\s+)?(.+)$/u', $title, $matches) === 1) {
            return trim($matches[1]);
        }

        return $title;
    }
}
