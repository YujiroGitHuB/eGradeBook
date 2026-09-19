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
$sub = $share ? implode(' · ', array_filter([(string)($share['section'] ?? ''), (string)($share['class_label'] ?? '')])) : '';
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
            <section class="sh-card">
                <header class="sh-head">
                    <div class="sh-head-ic"><i class="bi bi-trophy"></i></div>
                    <div>
                        <h1>Class ranking<?= $topN > 0 ? ' · Top ' . $topN : '' ?></h1>
                        <?php if ($sub !== ''): ?><p class="sh-sub"><?= $h($sub) ?></p><?php endif; ?>
                    </div>
                </header>

                <?php if (!$rows): ?>
                    <p class="sh-none">No one is ranked yet.</p>
                <?php else: ?>
                    <div class="sh-podium">
                        <?php foreach (array_slice($rows, 0, 3) as $r): $m = min((int)$r['rank'], 3); ?>
                            <div class="sh-pod sh-pod-<?= $m ?>">
                                <div class="sh-medal"><?= $MEDAL[$m - 1] ?></div>
                                <div class="sh-pod-name"><?= $h($r['name']) ?></div>
                                <?php if ($showGrades && isset($r['grade'])): ?>
                                    <div class="sh-pod-val"><?= $h($r['grade']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <table class="sh-table">
                        <thead>
                            <tr>
                                <th class="sh-c-rank">#</th>
                                <th>Student</th>
                                <?php if ($showGrades): ?>
                                    <th class="sh-c-num"><?= $termMode ? 'General Ave' : 'Grade' ?></th>
                                    <th class="sh-c-num"><?= $termMode ? 'Equivalent' : 'Remark' ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): $rk = (int)$r['rank']; ?>
                                <tr<?= $rk <= 3 ? ' class="sh-top"' : '' ?>>
                                    <td class="sh-c-rank"><?= $rk <= 3 ? $MEDAL[$rk - 1] : $rk ?></td>
                                    <td class="sh-name"><?= $h($r['name']) ?></td>
                                    <?php if ($showGrades): ?>
                                        <td class="sh-c-num sh-val"><?= $h($r['grade'] ?? '—') ?></td>
                                        <td class="sh-c-num">
                                            <?php if (isset($r['remark'])): ?>
                                                <span class="<?= !empty($r['pass']) ? 'sh-pass' : 'sh-fail' ?>"><?= $h($r['remark']) ?></span>
                                            <?php else: ?>—<?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
</body>

</html>
