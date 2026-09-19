<?php

/* ============================================================
   app/Views/share.php — public na Class ranking (walang login).
   In scope: $share (payload mula sa ShareRepo::findPublic, o null),
   $hub (null, o ang mga section ng teacher link), $hubToken, $activeToken,
   $unavailable (DB error), $nonce (para sa CSP), APP_ROOT.

   Lahat ng teksto ay galing sa database at dumaan sa h() — walang
   hilaw na output. Walang JS maliban sa pagpili ng theme.
   ============================================================ */

$h = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$when = fn($ts): string => $ts ? date('M j, Y · g:i A', strtotime((string)$ts)) : '';

$MEDAL = ['🥇', '🥈', '🥉'];
$rows = $share['rows'] ?? [];
$termMode = !empty($share['term_mode']);
$showGrades = !empty($share['show_grades']);
$topN = (int)($share['top_n'] ?? 0);
$label = (string)($share['class_label'] ?? '');

/* Podium = unang tatlong hanay; ang "Everyone else" ay ang natitira. Kapag
   Top 3 lang ang ibinahagi, walang listahan sa ibaba — hindi inuulit. */
$podium = array_slice($rows, 0, 3);
$rest = array_slice($rows, 3);
/* Ayos sa screen: 2nd · 1st · 3rd, gaya ng totoong podium. Pero kapag tabla
   ang dalawang nasa itaas, sunod-sunod na lang (kaliwa → kanan): walang
   "gitna" sa dalawang #1, at ang alpabetikong ayos nila ang dapat mabasa. */
$topTie = count($podium) > 1 && (int)$podium[0]['rank'] === (int)$podium[1]['rank'];
$podiumOrder = $topTie
    ? array_keys($podium)
    : [3 => [1, 0, 2], 2 => [1, 0], 1 => [0], 0 => []][count($podium)];
/* Ilan ang may parehong ranggo — para sa tatak na "Tied". */
$rankCount = array_count_values(array_map(fn($r) => (int)$r['rank'], $rows));

/* "NITOYA, R." → "RN"  ·  "Juan Dela Cruz" → "JC" */
$initials = function (string $name): string {
    if (strpos($name, ',') !== false) {
        [$last, $first] = array_map('trim', explode(',', $name, 2));
        $parts = [$first, $last];
    } else {
        $w = preg_split('/\s+/u', trim($name)) ?: [];
        $parts = [$w[0] ?? '', count($w) > 1 ? end($w) : ''];
    }
    $out = '';
    foreach ($parts as $p) if ($p !== '') $out .= mb_strtoupper(mb_substr($p, 0, 1));
    return $out !== '' ? $out : '?';
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $share ? 'Class ranking · ' . $h($share['section'] ?? '') : ($hub !== null ? 'Class rankings' : 'Link unavailable') ?> — eGradeBook</title>
    <?php include APP_ROOT . "/components/favico.php" ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/global.css?v=<?= filemtime(APP_ROOT . '/assets/css/global.css') ?>">
    <link rel="stylesheet" href="assets/css/share.css?v=<?= filemtime(APP_ROOT . '/assets/css/share.css') ?>">
</head>

<body class="bg-glow">
    <script nonce="<?= $h($nonce) ?>">
        /* Parehong theme ng eGradeBook/FormFlow kung nabuksan na sa browser na
           ito; kung hindi, ang setting ng device ng estudyante. */
        (function () {
            var t = null;
            try { t = localStorage.getItem('ff_theme'); } catch (e) { }
            if (!t && window.matchMedia) t = matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
            if (t === 'light') document.body.classList.add('light');
        })();
    </script>

    <main class="sh-wrap">
        <div class="sh-brand">
            <img src="assets/images/logo.png" width="28" height="28" alt="">
            <span>eGradeBook</span>
        </div>

        <?php if ($hub !== null): ?>
            <!-- Teacher link: pipili ang estudyante ng sariling section -->
            <section class="sh-card sh-picker">
                <header class="sh-head">
                    <div class="sh-head-ic"><i class="bi bi-people"></i></div>
                    <div>
                        <h1>Class rankings</h1>
                        <p class="sh-sub"><?= $hub ? 'Pick your section.' : 'No rankings are shared right now. Check back later.' ?></p>
                    </div>
                </header>
                <?php if ($hub): ?>
                    <nav class="sh-chips" aria-label="Sections">
                        <?php foreach ($hub as $x): $on = $x['token'] === $activeToken; ?>
                            <a class="sh-chip<?= $on ? ' is-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>
                                href="share.php?h=<?= $h($hubToken) ?>&amp;c=<?= $h($x['token']) ?>">
                                <span><?= $h($x['section']) ?></span>
                                <?php if ($x['label'] !== ''): ?><small><?= $h($x['label']) ?></small><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                    <?php if (!$share && ($_GET['c'] ?? '') !== ''): ?>
                        <p class="sh-none sh-gone">That section's ranking is no longer shared — pick another one above.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!$share && $hub !== null): ?>
            <?php /* teacher link na wala pang napiling section — ang picker na lang */ ?>
        <?php elseif (!$share): ?>
            <section class="sh-card sh-empty">
                <div class="sh-empty-ic"><i class="bi bi-link-45deg"></i></div>
                <?php if ($unavailable): ?>
                    <h1>Temporarily unavailable</h1>
                    <p>The ranking could not be loaded right now. Please try again in a moment.</p>
                <?php else: ?>
                    <h1>This link is no longer available</h1>
                    <p>It may have expired, or the teacher may have turned it off. Ask your teacher for a new link.</p>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="sh-card sh-rank">
                <header class="sh-hero">
                    <div class="sh-hero-ic"><i class="bi bi-trophy-fill"></i></div>
                    <div class="sh-hero-txt">
                        <p class="sh-eyebrow">Class ranking<?= $topN > 0 ? ' · Top ' . $topN : '' ?></p>
                        <h1><?= $h($share['section'] ?? '') ?></h1>
                        <?php if ($label !== ''): ?><p class="sh-sub"><?= $h($label) ?></p><?php endif; ?>
                    </div>
                </header>

                <?php if (!$rows): ?>
                    <p class="sh-none">No one is ranked yet.</p>
                <?php else: ?>
                    <!-- Podium: 2nd · 1st · 3rd. Ang taas ay ayon sa RANGGO (hindi
                         sa puwesto), kaya ang tabla sa una ay parehong mataas. -->
                    <ol class="pd pd-n<?= count($podium) ?>" aria-label="Top students">
                        <?php foreach ($podiumOrder as $i): $r = $podium[$i]; $rk = (int)$r['rank']; $t = min($rk, 3); ?>
                            <li class="pd-slot pd-t<?= $t ?>">
                                <div class="pd-person">
                                    <div class="pd-av" aria-hidden="true">
                                        <span><?= $h($initials($r['name'])) ?></span>
                                        <span class="pd-medal"><?= $MEDAL[$t - 1] ?></span>
                                    </div>
                                    <div class="pd-name"><?= $h($r['name']) ?></div>
                                    <?php if ($showGrades && isset($r['grade'])): ?>
                                        <div class="pd-grade"><?= $h($r['grade']) ?><?php if (isset($r['remark']) && $termMode): ?> <small><?= $h($r['remark']) ?></small><?php endif; ?></div>
                                    <?php endif; ?>
                                    <?php if ($rankCount[$rk] > 1): ?><span class="pd-tie">Tied</span><?php endif; ?>
                                </div>
                                <div class="pd-block"><span class="pd-rank"><?= $rk ?></span></div>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <?php if ($rest): ?>
                        <div class="ls-head">
                            <span>Everyone else <em><?= count($rest) ?></em></span>
                            <?php if (count($rest) > 8): ?>
                                <label class="ls-find">
                                    <i class="bi bi-search" aria-hidden="true"></i>
                                    <input type="search" id="lsFind" placeholder="Find your name" autocomplete="off" aria-label="Find your name">
                                </label>
                            <?php endif; ?>
                        </div>
                        <ol class="ls" id="lsList">
                            <?php foreach ($rest as $r): $rk = (int)$r['rank']; ?>
                                <li class="ls-row" data-name="<?= $h(mb_strtolower($r['name'])) ?>">
                                    <span class="ls-rk"><?= $rk ?></span>
                                    <span class="ls-av" aria-hidden="true"><?= $h($initials($r['name'])) ?></span>
                                    <span class="ls-name"><?= $h($r['name']) ?><?php if ($rankCount[$rk] > 1): ?> <span class="ls-tie">tied</span><?php endif; ?></span>
                                    <?php if ($showGrades): ?>
                                        <span class="ls-grade"><?= $h($r['grade'] ?? '—') ?><?php if (isset($r['remark'])): ?><small class="<?= !empty($r['pass']) ? 'sh-pass' : 'sh-fail' ?>"><?= $h($r['remark']) ?></small><?php endif; ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <p class="sh-none ls-nomatch" id="lsNone" hidden>No name matches. The top three are shown above.</p>
                    <?php endif; ?>
                <?php endif; ?>

                <footer class="sh-foot">
                    <span><i class="bi bi-clock-history"></i> As of <?= $h($when($share['updated_at'] ?? '')) ?></span>
                    <?php if (!empty($share['expires_at'])): ?>
                        <span><i class="bi bi-hourglass-split"></i> Link expires <?= $h($when($share['expires_at'])) ?></span>
                    <?php endif; ?>
                </footer>
            </section>
            <p class="sh-disclaimer">A snapshot shared by your teacher — it does not update by itself.
                Students with the same grade share the same rank. Grades here are not official until released by the school.</p>
        <?php endif; ?>
    </main>
    <?php if ($share && count($rest) > 8): ?>
        <script nonce="<?= $h($nonce) ?>">
            /* "Find your name" — sinasala lang ang listahan sa ibaba; ang
               podium ay laging nakikita. */
            (function () {
                var q = document.getElementById('lsFind'),
                    rows = document.querySelectorAll('#lsList .ls-row'),
                    none = document.getElementById('lsNone');
                q.addEventListener('input', function () {
                    var v = q.value.trim().toLowerCase(), shown = 0;
                    rows.forEach(function (r) {
                        var on = !v || r.getAttribute('data-name').indexOf(v) !== -1;
                        r.hidden = !on;
                        if (on) shown++;
                    });
                    none.hidden = shown > 0;
                });
            })();
        </script>
    <?php endif; ?>
</body>

</html>
