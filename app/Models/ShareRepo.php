<?php

namespace App\Models;

use App\Core\ClassScope;
use App\Core\Database;

/* ============================================================
   ShareRepo — grade_share_links: ang public na link ng Class ranking
   (share.php?t=<token>). Isa kada klase.

   Ito ang TANGING datos ng eGradeBook na nababasa nang walang login,
   kaya dito — hindi sa kliyente — ipinapatupad ang mga pagpipilian ng
   guro: kapag naka-off ang "Show grades", hindi na iniimbak ang grade;
   kapag "Top 10", ang iba ay hindi na iniimbak. Ang wala sa payload ay
   hindi kailanman maaabot ng publiko, anuman ang ipadala ng browser.

   Hindi kasama kailanman: student number, at ang listahan ng hindi
   naka-ranggo (INC/DRP/W). Sensitibong malaman ng buong klase kung
   sino ang nag-drop — at hindi iyon kailangan para sa ranking.
   ============================================================ */
class ShareRepo
{
    /* Mga pinapayagang pagpipilian — whitelist, dahil galing sa request. */
    public const EXPIRE_DAYS = [7, 30, 0];     // 0 = hanggang i-revoke
    public const TOP_N       = [0, 10, 3];     // 0 = lahat ng naka-ranggo

    private const MAX_ROWS = 1000;
    private const NAME_MAX = 150;

    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /* Ang link ng klaseng ito para sa guro (null kung wala). Kasama ang
       nag-expire na, para makita pa rin ng guro ang link at ma-Update ito. */
    public function find(ClassScope $c): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT token, show_grades, short_names, top_n, expire_days, expires_at, updated_at,
                    (expires_at IS NOT NULL AND expires_at <= NOW()) AS expired
               FROM grade_share_links
              WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?"
        );
        $stmt->bind_param('issss', $this->ownerId, $c->section, $c->schoolYear, $c->semester, $c->subject);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        return [
            'token'       => (string)$row['token'],
            'show_grades' => (int)$row['show_grades'] === 1,
            'short_names' => (int)$row['short_names'] === 1,
            'top_n'       => (int)$row['top_n'],
            'expire_days' => (int)$row['expire_days'],
            'expires_at'  => $row['expires_at'],
            'updated_at'  => $row['updated_at'],
            'expired'     => (int)$row['expired'] === 1,
        ];
    }

    /* Gumawa o i-update ang link ng klase. PAREHO ang token sa update, kaya
       ang URL na naipamigay na ay nananatiling gumagana. $meta = section/class
       label/mode na ipinapakita sa itaas ng page; $rows = ang naka-ranggo.
       Ibinabalik ang find() pagkatapos. */
    public function save(ClassScope $c, array $meta, array $rows, array $opt): array
    {
        $showGrades = !empty($opt['show_grades']) ? 1 : 0;
        $shortNames = !empty($opt['short_names']) ? 1 : 0;
        $topN       = in_array((int)$opt['top_n'], self::TOP_N, true) ? (int)$opt['top_n'] : 0;
        $days       = in_array((int)$opt['expire_days'], self::EXPIRE_DAYS, true) ? (int)$opt['expire_days'] : 30;
        $expires    = $days > 0 ? date('Y-m-d H:i:s', strtotime("+{$days} days")) : null;

        $payload = json_encode([
            'section'     => $this->clip((string)($meta['section'] ?? $c->section), 40),
            'class_label' => $this->clip((string)($meta['class_label'] ?? ''), 200),
            'term_mode'   => !empty($meta['term_mode']),
            'show_grades' => $showGrades === 1,
            'top_n'       => $topN,
            'rows'        => $this->cleanRows($rows, $showGrades === 1, $shortNames === 1, $topN),
        ], JSON_UNESCAPED_UNICODE);

        $existing = $this->find($c);
        if ($existing) {
            $stmt = $this->db->prepare(
                "UPDATE grade_share_links
                    SET payload=?, show_grades=?, short_names=?, top_n=?, expire_days=?, expires_at=?,
                        updated_at=CURRENT_TIMESTAMP
                  WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?"
            );
            $stmt->bind_param(
                'siiiisissss',
                $payload, $showGrades, $shortNames, $topN, $days, $expires,
                $this->ownerId, $c->section, $c->schoolYear, $c->semester, $c->subject
            );
        } else {
            /* 128-bit na random — hindi mahuhulaan, kaya ang link mismo ang susi. */
            $token = bin2hex(random_bytes(16));
            $stmt = $this->db->prepare(
                "INSERT INTO grade_share_links
                    (token, owner_id, section, school_year, semester, subject,
                     payload, show_grades, short_names, top_n, expire_days, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'sisssssiiiis',
                $token, $this->ownerId, $c->section, $c->schoolYear, $c->semester, $c->subject,
                $payload, $showGrades, $shortNames, $topN, $days, $expires
            );
        }
        $stmt->execute();
        $stmt->close();
        return $this->find($c) ?? [];
    }

    /* Patayin ang link. DELETE, hindi flag — ang susunod na Create ay bagong
       token, kaya ang lumang naipamigay ay hindi na muling bubuhay. */
    public function revoke(ClassScope $c): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM grade_share_links
              WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?"
        );
        $stmt->bind_param('issss', $this->ownerId, $c->section, $c->schoolYear, $c->semester, $c->subject);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        return $n > 0;
    }

    /* Para sa share.php (walang login, walang owner): ang payload ng isang
       buhay na token, o null kung wala, na-revoke, o nag-expire. */
    public static function findPublic(Database $db, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $stmt = $db->prepare(
            "SELECT payload, updated_at, expires_at FROM grade_share_links
              WHERE token=? AND (expires_at IS NULL OR expires_at > NOW())"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        $data = json_decode((string)$row['payload'], true);
        if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) return null;
        $data['updated_at'] = $row['updated_at'];
        $data['expires_at'] = $row['expires_at'];
        return $data;
    }

    /* Nilinis na hanay para sa publiko. Galing sa browser ang $rows, kaya
       bawat field ay sinusuri sa hugis nito — ang hindi tugma ay tinatanggal,
       hindi ipinapasa. Ang pagkakasunod ay muling inaayos ayon sa ranggo. */
    private function cleanRows(array $rows, bool $showGrades, bool $shortNames, int $topN): array
    {
        $out = [];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $r) {
            if (!is_array($r)) continue;
            $rank = (int)($r['rank'] ?? 0);
            $name = $this->clip((string)($r['name'] ?? ''), self::NAME_MAX);
            if ($rank < 1 || $rank > 100000 || $name === '') continue;
            if ($topN > 0 && $rank > $topN) continue;   // ang tabla sa hangganan ay kasama

            $row = ['rank' => $rank, 'name' => $shortNames ? self::shortName($name) : $name];
            if ($showGrades) {
                $g = (string)($r['grade'] ?? '');
                $k = (string)($r['remark'] ?? '');
                if (preg_match('/^\d{1,3}(\.\d{1,2})?%?$/', $g)) $row['grade'] = $g;
                if (preg_match('/^(Passed|Failed|\d\.\d{2})$/', $k)) {
                    $row['remark'] = $k;
                    $row['pass'] = !empty($r['pass']);
                }
            }
            $out[] = $row;
        }
        usort($out, fn($a, $b) => $a['rank'] <=> $b['rank']);
        return $out;
    }

    /* "Dela Cruz, Juan P." → "Dela Cruz, J."  ·  "Juan Dela Cruz" → "Juan D."
       Sapat para makilala ng kaklase ang sarili, pero hindi buong pangalan
       na mahahanap sa internet. */
    public static function shortName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if (strpos($name, ',') !== false) {
            [$last, $rest] = array_map('trim', explode(',', $name, 2));
            $ini = mb_substr($rest, 0, 1);
            return $ini !== '' ? $last . ', ' . mb_strtoupper($ini) . '.' : $last;
        }
        $parts = explode(' ', $name);
        if (count($parts) < 2) return $name;
        return $parts[0] . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
    }

    /* Isang linya, walang control character, may hangganang haba. */
    private function clip(string $s, int $max): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, $max);
    }
}
