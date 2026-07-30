<?php

namespace App\Core;

/* ============================================================
   ClassScope — the yunit ng gradebook: a "class" =
   (owner_id, school_year, semester, section, subject). Ipinapasa
   ito kung saan dati ay `section` lang ang naipapasa. Ang legacy
   data ay nasa class ('', '', '') — kaya kapag walang pinili ang
   frontend (blangkong sy/sem/subject), doon papasok, at eksaktong
   gaya ng dating gawi. See docs/class-scoping-plan.md.
   ============================================================ */
class ClassScope
{
    public string $schoolYear;
    public string $semester;
    public string $section;
    public string $subject;

    public function __construct(string $schoolYear, string $semester, string $section, string $subject)
    {
        $this->schoolYear = $schoolYear;
        $this->semester   = $semester;
        $this->section    = $section;
        $this->subject    = $subject;
    }

    /* Build from the request — POST first (writes), then GET (the sheet read),
       all trimmed. Missing = '' = the legacy class. */
    public static function fromRequest(): self
    {
        $g = fn(string $k): string => trim((string)($_POST[$k] ?? $_GET[$k] ?? ''));
        return new self($g('school_year'), $g('semester'), $g('section'), $g('subject'));
    }

    public function hasSection(): bool
    {
        return $this->section !== '';
    }
}
