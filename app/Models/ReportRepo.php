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

    /* Tanging mga format na ligtas ilagay sa isang <img src> AT kayang basahin
       ng jsPDF. Sadyang WALANG SVG: markup iyon, may kayang magdala ng script,
       at hindi rin ito iginuguhit ng jsPDF. */
    private const BANNER_RE = '#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#';

    /* ~3MB ng base64 (≈2.2MB na larawan). Pinapaliit naman ng kliyente sa
       ~1600px bago magpadala; ito ang hangganan kung sakaling hindi. */
    private const BANNER_MAX = 3145728;

    /* Laging kumpleto ang ibinabalik na array (blangko kung walang row),
       kaya hindi na kailangang mag-isa-isa ng null check ang tumatawag.

       SADYANG hindi kasama rito ang mismong `banner` — daan-daang KB iyon, at
       hinihingi ito sa bawat page load para sa print header. Ang bandila at
       sukat lang ang ipinapadala; ang datos ay hiwalay na hinihingi kapag
       kailangan na talaga (tingnan ang getBanner). */
    public function get(): array
    {
        $out = array_fill_keys(self::FIELDS, '');
        $out['has_banner'] = false;
        $out['banner_w'] = 0;
        $out['banner_h'] = 0;

        $stmt = $this->db->prepare(
            "SELECT school, department, title, faculty, note, banner_w, banner_h,
                    (banner IS NOT NULL AND banner <> '') AS hb
               FROM grade_report_header WHERE owner_id = ?"
        );
        $stmt->bind_param('i', $this->ownerId);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            foreach (self::FIELDS as $f) $out[$f] = (string)($row[$f] ?? '');
            $out['has_banner'] = ((int)$row['hb'] === 1);
            $out['banner_w'] = (int)$row['banner_w'];
            $out['banner_h'] = (int)$row['banner_h'];
        }
        $stmt->close();
        return $out;
    }

    /* Ang data URI mismo ('' kung wala). Hiwalay na tawag — tingnan ang get(). */
    public function getBanner(): string
    {
        $stmt = $this->db->prepare("SELECT banner FROM grade_report_header WHERE owner_id = ?");
        $stmt->bind_param('i', $this->ownerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (string)($row['banner'] ?? '');
    }

    /* Itakda o alisin ang banner. $dataUri = '' ay nag-aalis.
       Nagbabalik ng '' kung tinanggap, o mensahe ng pagkakamali. */
    public function saveBanner(string $dataUri, int $w, int $h): string
    {
        $dataUri = trim($dataUri);

        if ($dataUri === '') {
            $stmt = $this->db->prepare(
                "UPDATE grade_report_header SET banner=NULL, banner_w=0, banner_h=0 WHERE owner_id = ?"
            );
            $stmt->bind_param('i', $this->ownerId);
            $stmt->execute();
            $stmt->close();
            return '';
        }

        if (strlen($dataUri) > self::BANNER_MAX) return 'That image is too large. Try a smaller one.';
        if (!preg_match(self::BANNER_RE, $dataUri)) return 'Use a PNG or JPG image.';
        if ($w < 1 || $h < 1) return 'Could not read the image size.';

        /* Ang row ay puwedeng wala pa kung banner lang ang unang itinakda. */
        $stmt = $this->db->prepare(
            "INSERT INTO grade_report_header (owner_id, banner, banner_w, banner_h) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE banner=VALUES(banner), banner_w=VALUES(banner_w), banner_h=VALUES(banner_h)"
        );
        $stmt->bind_param('isii', $this->ownerId, $dataUri, $w, $h);
        $stmt->execute();
        $stmt->close();
        return '';
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
