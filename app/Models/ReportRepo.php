<?php

namespace App\Models;

use App\Core\Database;

/* ============================================================
   ReportRepo — grade_report_header: ang ulo ng mga PDF export
   (paaralan, departamento, pamagat, guro, footer note).

   Isa kada guro, hindi kada klase. Iisa ang paaralan at iisa ang
   pipirma sa bawat section at bawat semestre — kaya walang saysay
   na ipatipa ito nang paulit-ulit, at walang scope column dito.

   Blangko ang lahat ng field bilang default, at ang blangko ay
   TINATANGGAL sa PDF (hindi ipinapakitang walang laman). Kaya ang
   gurong hindi ito hinawakan kailanman ay makakakuha ng eksaktong
   dating anyo ng report.
   ============================================================ */
class ReportRepo
{
    public const FIELDS = ['school', 'department', 'title', 'faculty', 'note'];

    /* Haba ayon sa schema — pinuputol dito para hindi tahimik na putulin
       ng MySQL (o tumanggi sa STRICT mode). */
    private const MAXLEN = [
        'school' => 150, 'department' => 150, 'title' => 120,
        'faculty' => 150, 'note' => 255,
    ];

    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /* Laging kumpleto ang ibinabalik na array (blangko kung walang row),
       kaya hindi na kailangang mag-isa-isa ng null check ang tumatawag. */
    public function get(): array
    {
        $out = array_fill_keys(self::FIELDS, '');
        $stmt = $this->db->prepare(
            "SELECT school, department, title, faculty, note FROM grade_report_header WHERE owner_id = ?"
        );
        $stmt->bind_param('i', $this->ownerId);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            foreach (self::FIELDS as $f) $out[$f] = (string)($row[$f] ?? '');
        }
        $stmt->close();
        return $out;
    }

    /* Buong palit — ang lima ay laging magkasamang ipinapadala ng modal.
       Nagbabalik ng nilinis na halaga para ang IPINAKITA sa guro ay iyon din
       ang naka-imbak (kung pinutol, dapat niyang makita agad). */
    public function save(array $vals): array
    {
        $clean = [];
        foreach (self::FIELDS as $f) {
            $v = trim((string)($vals[$f] ?? ''));
            /* Isang linya lang bawat field — ang bagong linya ay sisira sa
               pagkakahanay ng PDF, at walang paraan sa modal para makita
               kung saan ito mapupunta. */
            $v = preg_replace('/\s*[\r\n]+\s*/u', ' ', $v);
            $clean[$f] = mb_substr($v, 0, self::MAXLEN[$f]);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO grade_report_header (owner_id, school, department, title, faculty, note)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE school=VALUES(school), department=VALUES(department),
                                     title=VALUES(title), faculty=VALUES(faculty), note=VALUES(note)"
        );
        $stmt->bind_param(
            'isssss',
            $this->ownerId,
            $clean['school'],
            $clean['department'],
            $clean['title'],
            $clean['faculty'],
            $clean['note']
        );
        $stmt->execute();
        $stmt->close();
        return $clean;
    }
}
