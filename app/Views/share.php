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

/* Pinagsasama ang magkatabla sa iisang GRUPO (iisang numero), para hindi
   isipin ng estudyante na "tabla kami, bakit 5 ako at 6 siya". Ang podium at
   ang listahan ay parehong gumagamit ng grupo, kaya hindi kailanman nahahati
   ang isang tabla sa pagitan ng dalawa. */
$groups = [];
foreach ($rows as $r) {
    $k = (int)$r['rank'];
    $last = count($groups) - 1;
    if ($last >= 0 && $groups[$last]['rank'] === $k) $groups[$last]['rows'][] = $r;
    else $groups[] = ['rank' => $k, 'rows' => [$r]];
}

/* Podium: mga grupong ranggo 1–3, hanggang tatlong tao, buong grupo lang.
   (1,2,3,3 → nasa podium ang 1 at 2; ang dalawang #3 ay magkasama sa listahan.)
   Ang unang grupo ay laging nasa podium kahit higit sa tatlo ang tabla. */
$podiumG = [];
$podiumN = 0;
foreach ($groups as $g) {
    if ($g['rank'] > 3) break;
    $c = count($g['rows']);
    if ($podiumG && $podiumN + $c > 3) break;
    $podiumG[] = $g;
    $podiumN += $c;
    if ($podiumN >= 3) break;
}
$restG = array_slice($groups, count($podiumG));
$restN = array_sum(array_map(fn($g) => count($g['rows']), $restG));

/* Ayos sa screen: 2nd · 1st · 3rd gaya ng totoong podium — kapag walang
   tabla lang. Kapag may tabla, sunod-sunod (kaliwa → kanan) ang mga grupo. */
$plain = count($podiumG) === $podiumN;
$podiumOrder = $plain && $podiumN === 3 ? [1, 0, 2]
    : ($plain && $podiumN === 2 ? [1, 0] : array_keys($podiumG));
/* Lapad: bawat tao ay isang hanay; higit sa tatlo → isang buong hanay na
   bumabalot (CSP: walang inline style, kaya klase ang gamit). */
$podiumCols = $podiumN > 3 ? 1 : $podiumN;

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
                    <!-- Podium — iisang bloke at iisang numero bawat grupo; ang
                         magkatabla ay magkasamang nakatayo sa iisang bloke. -->
                    <ol class="pd pd-cols-<?= $podiumCols ?><?= $podiumN === 1 ? ' pd-solo' : '' ?>" aria-label="Top students">
                        <?php foreach ($podiumOrder as $gi): $g = $podiumG[$gi]; $rk = $g['rank']; $t = min($rk, 3); $n = count($g['rows']); ?>
                            <li class="pd-slot pd-t<?= $t ?> pd-span-<?= $podiumCols === 1 ? 1 : $n ?><?= $n > 1 ? ' pd-tied' : '' ?>">
                                <div class="pd-people">
                                    <?php foreach ($g['rows'] as $r): ?>
                                        <div class="pd-person">
                                            <div class="pd-av" aria-hidden="true">
                                                <span><?= $h($initials($r['name'])) ?></span>
                                                <span class="pd-medal"><?= $MEDAL[$t - 1] ?></span>
                                            </div>
                                            <div class="pd-name"><?= $h($r['name']) ?></div>
                                            <?php if ($showGrades && isset($r['grade'])): ?>
                                                <div class="pd-grade"><?= $h($r['grade']) ?><?php if (isset($r['remark']) && $termMode): ?> <small><?= $h($r['remark']) ?></small><?php endif; ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="pd-block">
                                    <span class="pd-rank"><?= $rk ?></span>
                                    <?php if ($n > 1): ?><span class="pd-tielabel">Tied · <?= $n ?> students</span><?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <?php if ($restG): ?>
                        <div class="ls-head">
                            <span>Everyone else <em><?= $restN ?></em></span>
                            <?php if ($restN > 8): ?>
                                <label class="ls-find">
                                    <i class="bi bi-search" aria-hidden="true"></i>
                                    <input type="search" id="lsFind" placeholder="Find your name" autocomplete="off" aria-label="Find your name">
                                </label>
                            <?php endif; ?>
                        </div>
                        <!-- Isang hanay bawat GRUPO: ang magkatabla ay nasa ilalim ng
                             iisang numero, hindi magkakahiwalay na hanay. -->
                        <ol class="ls" id="lsList">
                            <?php foreach ($restG as $g): $n = count($g['rows']); ?>
                                <li class="ls-row<?= $n > 1 ? ' ls-tied' : '' ?>">
                                    <span class="ls-rk"><?= $g['rank'] ?><?php if ($n > 1): ?><small>tied</small><?php endif; ?></span>
                                    <div class="ls-people">
                                        <?php foreach ($g['rows'] as $r): ?>
                                            <div class="ls-p" data-name="<?= $h(mb_strtolower($r['name'])) ?>">
                                                <span class="ls-av" aria-hidden="true"><?= $h($initials($r['name'])) ?></span>
                                                <span class="ls-name"><?= $h($r['name']) ?></span>
                                                <?php if ($showGrades): ?>
                                                    <span class="ls-grade"><?= $h($r['grade'] ?? '—') ?><?php if (isset($r['remark'])): ?><small class="<?= !empty($r['pass']) ? 'sh-pass' : 'sh-fail' ?>"><?= $h($r['remark']) ?></small><?php endif; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <p class="sh-none ls-nomatch" id="lsNone" hidden>No name matches. The top students are shown above.</p>
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
    <?php if ($share && $restN > 8): ?>
        <script nonce="<?= $h($nonce) ?>">
            /* "Find your name" — sinasala lang ang listahan sa ibaba; ang
               podium ay laging nakikita. Nawawala ang isang grupo ng tabla
               kapag wala ni isa sa kanila ang tumugma. */
            (function () {
                var q = document.getElementById('lsFind'),
                    groups = document.querySelectorAll('#lsList .ls-row'),
                    none = document.getElementById('lsNone');
                q.addEventListener('input', function () {
                    var v = q.value.trim().toLowerCase(), shown = 0;
                    groups.forEach(function (g) {
                        var any = false;
                        g.querySelectorAll('.ls-p').forEach(function (p) {
                            var on = !v || p.getAttribute('data-name').indexOf(v) !== -1;
                            p.hidden = !on;
                            if (on) any = true;
                        });
                        g.hidden = !any;
                        if (any) shown++;
                    });
                    none.hidden = shown > 0;
                });
            })();
        </script>
    <?php endif; ?>
</body>

</html>
