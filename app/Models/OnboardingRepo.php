<?php

namespace App\Models;

use App\Core\Database;

/* ============================================================
   grade_onboarding — kung nakita na ng guro ang guided tour at ang
   pinakabagong What's New. Kada account (owner_id), para sumusunod sa
   guro sa anumang computer at hindi naitatago ng shared na browser.
   ============================================================ */
class OnboardingRepo
{
    /* Mga table na nagpapakilala ng gurong DATI NANG gumagamit. Sadyang hindi
       kasama ang grade_transmute: kusang nilalagyan ng default ang mga banda
       sa unang pagbukas, kaya ang gurong minsang nag-login at umalis ay
       mapagkakamalang dati nang gumagamit. */
    private const USAGE_TABLES = [
        'grade_activities',
        'grade_classes',
        'grade_settings',
        'grade_categories',
        'grade_pinned_sections',
        'grade_report_header',
    ];

    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /* ['tour' => kusang simulan ang tour?, 'whatsnew_seen' => 'Y-m-d' | ''].
       Sa unang tawag ay nililikha ang row. Ang guro na dati nang may data ay
       itinatalang tapos sa tour — ang tour ay para sa bagong user, hindi para
       sa gurong ilang semestre nang gumagamit bago idinagdag ang feature. */
    public function state(): array
    {
        $row = $this->row();
        if ($row === null) {
            $done = $this->hasGradebookData() ? 1 : 0;
            $ins = $this->db->prepare("INSERT IGNORE INTO grade_onboarding (owner_id, tour_done) VALUES (?, ?)");
            $ins->bind_param('ii', $this->ownerId, $done);
            $ins->execute();
            $ins->close();
            $row = $this->row() ?? ['tour_done' => $done, 'whatsnew_seen' => ''];
        }
        return [
            'tour'          => (int)$row['tour_done'] === 0,
            'whatsnew_seen' => (string)$row['whatsnew_seen'],
        ];
    }

    public function markTourDone(): void
    {
        $st = $this->db->prepare("INSERT INTO grade_onboarding (owner_id, tour_done) VALUES (?, 1)
            ON DUPLICATE KEY UPDATE tour_done = 1");
        $st->bind_param('i', $this->ownerId);
        $st->execute();
        $st->close();
    }

    /* $version ay isang napatunayang Y-m-d (tingnan ang controller). GREATEST
       para ang lumang tab na naiwang bukas ay hindi nagbabalik sa mas lumang
       bersyon — pareho ang anyo, kaya ang string compare ay date compare. */
    public function markWhatsNewSeen(string $version): void
    {
        $st = $this->db->prepare("INSERT INTO grade_onboarding (owner_id, whatsnew_seen) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE whatsnew_seen = GREATEST(whatsnew_seen, VALUES(whatsnew_seen))");
        $st->bind_param('is', $this->ownerId, $version);
        $st->execute();
        $st->close();
    }

    private function row(): ?array
    {
        $st = $this->db->prepare("SELECT tour_done, whatsnew_seen FROM grade_onboarding WHERE owner_id = ?");
        $st->bind_param('i', $this->ownerId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: null;
    }

    private function hasGradebookData(): bool
    {
        foreach (self::USAGE_TABLES as $t) {
            $st = $this->db->prepare("SELECT 1 FROM `$t` WHERE owner_id = ? LIMIT 1");
            $st->bind_param('i', $this->ownerId);
            $st->execute();
            $found = $st->get_result()->fetch_row() !== null;
            $st->close();
            if ($found) return true;
        }
        return false;
    }
}
