<?php

namespace App\Models;

use App\Core\Database;

/* ============================================================
   FormSubjectRepo — grade_form_subject: kung aling SUBJECT ang
   may-ari ng isang FormFlow form sa loob ng isang section.

   Bakit kailangan: walang `subject` ang FormFlow (wala sa `forms`,
   wala rin sa `form_responses`), kaya per-SECTION lang matutuklas
   ang form columns. Kapag dalawa ang subject ng isang section,
   lalabas sana ang form ng kabilang subject sa bawat klase at
   papasok pa sa grado.

   SADYANG HINDI class-scoped ang talahanayang ito (tulad ng
   grade_transmute at grade_pinned_sections): isang beses mong
   iaangkin ang form, at tumatalab na ito sa lahat ng klase ng
   section — pati sa mga klaseng gagawin pa lang sa susunod na
   semestre o taon. Ito ang pagkakaiba nito sa grade_form_meta.hidden,
   na per-klase at kailangang ulitin.

   Blangkong subject = hindi inaangkin = lumalabas kahit saan
   (eksaktong dating gawi).
   ============================================================ */
class FormSubjectRepo
{
    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /* [form_id => subject] para sa buong section. Ang mga walang row
       ay wala rito, at iyon ang ibig sabihin ng "hindi inaangkin". */
    public function mapForSection(string $section): array
    {
        $admin = $this->ownerId;
        $stmt = $this->db->prepare("SELECT form_id, subject FROM grade_form_subject WHERE owner_id=? AND section=?");
        $stmt->bind_param('is', $admin, $section);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) $out[(int)$r['form_id']] = (string)$r['subject'];
        $stmt->close();
        return $out;
    }

    /* Iangkin ang form para sa isang subject. Blangko = alisin ang pag-angkin
       (babalik sa paglabas sa lahat ng klase ng section). */
    public function set(string $section, int $formId, string $subject): void
    {
        $admin = $this->ownerId;
        $subject = trim($subject);
        if ($subject === '') {
            $stmt = $this->db->prepare("DELETE FROM grade_form_subject WHERE owner_id=? AND section=? AND form_id=?");
            $stmt->bind_param('isi', $admin, $section, $formId);
        } else {
            $stmt = $this->db->prepare("INSERT INTO grade_form_subject (owner_id, section, form_id, subject) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE subject = VALUES(subject)");
            $stmt->bind_param('isis', $admin, $section, $formId, $subject);
        }
        $stmt->execute();
        $stmt->close();
    }
}
