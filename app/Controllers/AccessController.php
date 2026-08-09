<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\AccessRepo;

/* Pamamahala ng allowlist ng app (More ▸ Manage access…).

   SUPERADMIN LANG. Nakapasa na sa Auth::requireAccess ang bawat tumatawag
   dito — pero ang pagkakaroon ng access ay hindi karapatang MAGBIGAY ng
   access. Kung hindi, ang unang gurong bibigyan mo ay makakapagpapasok na
   ng kahit sino, at wala nang saysay ang allowlist. */
class AccessController extends Controller
{
    /* Iisang pinto para sa dalawang aksyon — huwag itong laktawan. */
    private function denyIfNotSuperadmin(): bool
    {
        if (Auth::isSuperadmin()) return false;
        $this->fail('Only a superadmin can manage access.');
        return true;
    }

    public function listAccounts(): void
    {
        if ($this->denyIfNotSuperadmin()) return;
        $repo = new AccessRepo($this->db);
        $this->ok([
            'accounts' => $repo->listAccounts(),
            'me'       => $this->ownerId,
        ]);
    }

    public function setAccess(): void
    {
        if ($this->denyIfNotSuperadmin()) return;

        $adminId = intval($this->post('admin_id', 0));
        $grant   = ($this->post('grant', '0') === '1');

        $repo = new AccessRepo($this->db);
        if (!$repo->accountExists($adminId)) {
            $this->fail('That account no longer exists in FormFlow.');
            return;
        }

        try {
            if ($grant) $repo->grant($adminId, $this->ownerId);
            else        $repo->revoke($adminId);
            /* Ibinabalik ang buong listahan para hindi na maghula ang modal
               kung ano ang estado matapos ang isang pagbabago. */
            $this->ok(['granted' => $grant, 'accounts' => $repo->listAccounts()]);
        } catch (\Throwable $e) {
            error_log('eGradeBook set_access failed: ' . $e);
            $this->fail('Could not change that account\'s access.');
        }
    }
}
