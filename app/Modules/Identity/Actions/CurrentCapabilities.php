<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Contracts\AuthorizedIdentity;
use Illuminate\Support\Facades\Config;

final class CurrentCapabilities
{
    /** Stable UI hints. Resource policies, assignments, workflow state and fresh command authorization still apply.
     * @return list<string>
     */
    public function forSession(AuthorizedIdentity $actor, bool $recent): array
    {
        $result = ['profile.view', 'profile.update', 'sessions.manage', 'security.password.confirm'];
        if ($recent) {
            $result[] = 'security.password.change';
        }
        $map = ['notifications.view' => ['notifications.self.read'], 'notifications.manage' => ['notifications.self.manage']];
        if ($actor->kind === 'staff') {
            $map += [
                'admin.dashboard.view' => ['reporting.read'],
                'admin.customers.view' => ['customers.directory.read'],
                'project_requests.view' => ['intake.read'],
                'project_requests.assign' => ['intake.read', 'intake.assign'],
                'project_requests.review' => ['intake.read', 'intake.review'],
                'project_requests.request_information' => ['intake.read', 'intake.information'],
                'discovery.view' => ['intake.read', 'discovery.read'],
                'discovery.manage' => ['intake.read', 'discovery.read', 'discovery.manage'],
                'proposals.view' => ['intake.read', 'proposals.read'],
                'proposals.create' => ['intake.read', 'proposals.read', 'proposals.create'],
                'projects.view' => ['projects.read'],
                'projects.manage' => ['projects.read', 'projects.manage'],
                'projects.team.manage' => ['projects.read', 'projects.team.manage'],
                'projects.milestones.manage' => ['projects.read', 'projects.milestones.manage'],
                'projects.updates.publish' => ['projects.read', 'projects.updates.publish'],
                'categories.manage' => ['taxonomy.manage'],
                'reports.view' => ['reporting.read'],
                'audit.investigate' => ['audit.investigate'],
            ];
            if ($recent) {
                $map['staff.authorization.manage'] = ['identity.staff.manage'];
            }
        } else {
            $result = [...$result, 'categories.view', 'customer_profile.view', 'customer_profile.update', 'project_requests.view', 'project_requests.create'];
            $map += ['projects.view' => ['projects.self.read'], 'proposals.view' => ['proposals.self.read']];
            if ($actor->verifiedEmail) {
                $result = [...$result, 'project_requests.submit', 'project_requests.documents.upload'];
                if ($recent) {
                    $map['proposals.accept'] = ['proposals.self.accept'];
                    $map['projects.completion.confirm'] = ['projects.self.read', 'projects.self.confirm'];
                }
            }
        }
        if (Config::boolean('ai.enabled') && $actor->verifiedEmail) {
            $map['ai.request'] = [$actor->kind === 'staff' ? 'ai.use' : 'ai.self.use'];
        }
        foreach ($map as $capability => $required) {
            if (! $actor->verifiedEmail && in_array($capability, ['admin.dashboard.view', 'reports.view', 'audit.investigate'], true)) {
                continue;
            }
            if ($capability === 'admin.customers.view' && ! $actor->allows('intake.read') && ! $actor->allows('projects.read')) {
                continue;
            }
            if (array_diff($required, $actor->permissions) === []) {
                $result[] = $capability;
            }
        }
        sort($result);

        return array_values(array_unique($result));
    }
}
