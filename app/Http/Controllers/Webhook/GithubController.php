<?php

namespace App\Http\Controllers\Webhook;

use App\CircleCI;
use App\Http\Controllers\Controller;
use App\Models\GithubInstall;
use App\Models\Project;
use Illuminate\Http\Request;

class GithubController extends Controller {
  public function __invoke(Request $request) {
    // Validate signature
    $sig_check = 'sha1=' . hash_hmac('sha1', $request->getContent(), env('GITHUB_WEBHOOK_SECRET'));
    if ($sig_check !== $request->header('x-hub-signature')) {
      abort(403, 'Invalid token');
    }

    $event_name = $request->header('X-GitHub-Event');
    $handler_name = 'handle' . studly_case($event_name);
    if (method_exists($this, $handler_name)) {
      $this->{$handler_name}($request);
    } else {
      return 'No handler for ' . $event_name;
    }
  }

  // https://developer.github.com/v3/activity/events/types/#installationevent
  public function handleInstallation(Request $request) {
    $install = GithubInstall::firstOrCreate([
      'install_id' => $request->input('installation.id'),
    ]);

    switch ($request->input('action')) {
      case 'created':
        // Add every repo that the app was installed to
        $org_name = $request->input('installation.account.login');
        $repos = $request->input('repositories') ?? [];
        $this->addRepositories($org_name, $install, $repos);
        break;

      case 'deleted':
        Project::where('github_install_id', $install->id)
          ->update(['active' => false]);
        $install->delete();
        break;
    }
  }

  // https://developer.github.com/v3/activity/events/types/#installationrepositoriesevent
  public function handleInstallationRepositories(Request $request) {
    $install = GithubInstall::firstOrCreate([
      'install_id' => $request->input('installation.id'),
    ]);

    // Add new repositories, and remove old ones
    $org_name = $request->input('installation.account.login');
    $this->addRepositories(
      $org_name,
      $install,
      $request->input('repositories_added')
    );

    $removed_repos = $request->input('repositories_removed');
    $removed_repo_names = [];
    foreach ($removed_repos as $repo) {
      $removed_repo_names[] = $repo['name'];
    }

    // Deactive any removed repositories
    Project::whereIn('repo_name', $removed_repo_names)
      ->where('org_name', $org_name)
      ->update(['active' => false]);
  }

  private function handleStatus(Request $request) {
    switch ($request->input('context')) {
      case 'ci/circleci':
        CircleCI::analyzeBuildFromURL($request->input('target_url'));
        break;

      default:
        return 'Unknown context "' . $request->input('context') . '"';
    }
  }

  private function addRepositories(string $org_name, GitHubInstall $install, array $repos) {
    foreach ($repos as $repo) {
      Project::updateOrCreate(
        [
          'host' => 'github',
          'org_name' => $org_name,
          'repo_name' => $repo['name'],
        ],
        [
          'active' => true,
          'github_install_id' => $install->id,
        ]
      );
    }
  }
}