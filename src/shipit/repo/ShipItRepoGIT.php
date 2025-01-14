<?hh // strict
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/0rx59too
 */
namespace Facebook\ShipIt;

use namespace HH\Lib\{Str, C, Dict};

final class ShipItRepoGITException extends ShipItRepoException {}

/**
 * GIT specialization of ShipItRepo
 */
class ShipItRepoGIT
  extends ShipItRepo
  implements ShipItSourceRepo, ShipItDestinationRepo {

  const type TSubmoduleSpec = shape(
    'name' => string,
    'path' => string,
    'url' => string,
  );

  private string $branch = 'master';
  private ShipItTempDir $fakeHome;

  public function __construct(string $path, string $branch) {
    $this->fakeHome = new ShipItTempDir('fake_home_for_git');
    parent::__construct($path, $branch);
  }

  <<__Override>>
  public function setBranch(string $branch): bool {
    $this->branch = $branch;
    $this->gitCommand('checkout', $branch);
    return true;
  }

  <<__Override>>
  public function updateBranchTo(string $base_rev): void {
    /* HH_FIXME[4276] truthiness test on string */
    if (!$this->branch) {
      throw new ShipItRepoGITException(
        $this,
        'setBranch must be called first.',
      );
    }
    $this->gitCommand('checkout', '-B', $this->branch, $base_rev);
  }

  <<__Override>>
  public function getHeadChangeset(): ?ShipItChangeset {
    $rev = $this->gitCommand('rev-parse', $this->branch);

    $rev = Str\trim($rev);
    if (Str\trim($rev) === '') {
      return null;
    }
    return $this->getChangesetFromID($rev);
  }

  public function findLastSourceCommit(ImmSet<string> $roots): ?string {
    $log = $this->gitCommand(
      'log',
      '-1',
      '--grep',
      '^\\(fb\\)\\?shipit-source-id: \\?[a-z0-9]\\+\\s*$',
      ...$roots
    );
    $log = Str\trim($log);
    $matches = null;
    if (
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      !\preg_match_all(
        '/^ *(fb)?shipit-source-id: ?(?<commit>[a-z0-9]+)$/m',
        $log,
        inout $matches,
      )
    ) {
      return null;
    }
    if (!\is_array($matches)) {
      return null;
    }
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    if (!\array_key_exists('commit', $matches)) {
      return null;
    }
    if (!\is_array($matches['commit'])) {
      return null;
    }
    return C\last($matches['commit']);
  }

  public function findNextCommit(
    string $revision,
    ImmSet<string> $roots,
  ): ?string {
    $log = $this->gitCommand(
      'log',
      $revision.'..',
      '--ancestry-path',
      '--no-merges',
      '--oneline',
      ...$roots
    );

    $log = Str\trim($log);
    if (Str\trim($log) === '') {
      return null;
    }
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    $revs = \explode("\n", Str\trim($log));
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    list($rev) = \explode(' ', \array_pop(inout $revs), 2);
    return $rev;
  }

  private static function parseHeader(string $header): ShipItChangeset {
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    $parts = \explode("\n\n", Str\trim($header), 2);
    $envelope = $parts[0];
    $message = C\count($parts) === 2 ? Str\trim($parts[1]) : '';

    $start_of_filelist = Str\search_last($message, "\n---\n ");
    if ($start_of_filelist !== null) {
      // Get rid of the file list when a summary is
      // included in the commit message
      $message = Str\trim(Str\slice($message, 0, $start_of_filelist));
    }

    $changeset = (new ShipItChangeset())->withMessage($message);

    $envelope = Str\replace_every($envelope, dict["\n\t" => ' ', "\n " => ' ']);
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    foreach (\explode("\n", $envelope) as $line) {
      $colon = Str\search($line, ':');
      if ($colon === null) {
        continue;
      }
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      list($key, $value) = \explode(':', $line, 2);
      $value = Str\trim($value);
      switch (Str\lowercase(Str\trim($key))) {
        case 'from':
          $changeset = $changeset->withAuthor($value);
          break;
        case 'subject':
          /* HH_IGNORE_ERROR[2049] __PHPStdLib */
          /* HH_IGNORE_ERROR[4107] __PHPStdLib */
          if (!\strncasecmp($value, '[PATCH] ', 8)) {
            $value = Str\trim(Str\slice($value, 8));
          }
          $changeset = $changeset->withSubject($value);
          break;
        case 'date':
          /* HH_IGNORE_ERROR[2049] __PHPStdLib */
          /* HH_IGNORE_ERROR[4107] __PHPStdLib */
          $changeset = $changeset->withTimestamp(\strtotime($value));
          break;
      }

    }

    return $changeset;
  }

  public function getNativePatchFromID(string $revision): string {
    return $this->gitCommand(
      'format-patch',
      '--no-renames',
      '--no-stat',
      '--stdout',
      // use full SHAs to avoid inconsistent SHAs between calls
      '--full-index',
      '--format=', // Contain nothing but the code changes
      '-1',
      $revision,
    );
  }

  public function getNativeHeaderFromID(string $revision): string {
    $patch = $this->getNativePatchFromID($revision);
    return $this->getNativeHeaderFromIDWithPatch($revision, $patch);
  }

  private function getNativeHeaderFromIDWithPatch(
    string $revision,
    string $patch,
  ): string {
    $full_patch = $this->gitCommand(
      'format-patch',
      '--always',
      '--no-renames',
      '--no-stat',
      '--stdout',
      // use full SHAs to avoid inconsistent SHAs between calls
      '--full-index',
      '-1',
      $revision,
    );
    if (Str\length($patch) === 0) {
      // This is an empty commit, so everything is the header.
      return $full_patch;
    }
    $index = Str\search($full_patch, $patch);
    if ($index !== null) {
      return Str\slice($full_patch, 0, $index);
    }
    throw new ShipItRepoGITException($this, 'Could not extract patch header.');
  }

  public function getChangesetFromID(string $revision): ShipItChangeset {
    $patch = $this->getNativePatchFromID($revision);
    $header = $this->getNativeHeaderFromIDWithPatch($revision, $patch);
    $changeset = self::getChangesetFromExportedPatch($header, $patch);
    $changeset = $changeset->withID($revision);
    return $changeset;
  }

  <<__Override>>
  public static function getDiffsFromPatch(
    string $patch,
  ): ImmVector<ShipItDiff> {
    $diffs = Vector {};
    foreach (ShipItUtil::parsePatch($patch) as $hunk) {
      $diff = self::parseDiffHunk($hunk);
      if ($diff !== null) {
        $diffs[] = $diff;
      }
    }
    return $diffs->toImmVector();
  }

  public static function getChangesetFromExportedPatch(
    string $header,
    string $patch,
  ): ShipItChangeset {
    $ret = self::parseHeader($header);
    return $ret->withDiffs(self::getDiffsFromPatch($patch));
  }

  /**
   * Render patch suitable for `git am`
   */
  public static function renderPatch(ShipItChangeset $patch): string {
    /* Insert a space before patterns that will make `git am` think that a
     * line in the commit message is the start of a patch, which is an artifact
     * of the way `git am` tries to tell where the message ends and the diffs
     * begin. This fix is a hack; a better fix might be to use `git apply` and
     * `git commit` directly instead of `git am`, but this is an edge-case so
     * it's not worth it right now.
     *
     * https://github.com/git/git/blob/77bd3ea9f54f1584147b594abc04c26ca516d987/builtin/mailinfo.c#L701
     */
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    $message = \preg_replace(
      '/^(diff -|Index: |---(?:\s\S|\s*$))/m',
      ' $1',
      $patch->getMessage(),
    );

    // Mon Sep 17 is a magic date used by format-patch to distinguish from real
    // mailboxes. cf. https://git-scm.com/docs/git-format-patch
    $ret = "From {$patch->getID()} Mon Sep 17 00:00:00 2001\n".
      "From: {$patch->getAuthor()}\n".
      "Date: ".
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
            /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      \date('r', $patch->getTimestamp()).
      "\n".
      "Subject: [PATCH] {$patch->getSubject()}\n\n".
      "{$message}\n---\n\n";
    foreach ($patch->getDiffs() as $diff) {
      $path = $diff['path'];
      $body = $diff['body'];

      $ret .= "diff --git a/{$path} b/{$path}\n{$body}";
    }
    $ret .= "--\n1.7.9.5\n";
    return $ret;
  }

  /**
   * Commit a standardized patch to the repo
   */
  public function commitPatch(ShipItChangeset $patch): string {
    if ($patch->getDiffs()->count() === 0) {
      // This is an empty commit, which `git am` does not handle properly.
      $this->gitCommand(
        'commit',
        '--allow-empty',
        '--author',
        $patch->getAuthor(),
        '--date',
        (string)$patch->getTimestamp(),
        '-m',
        self::getCommitMessage($patch),
      );
      return $this->getHEADSha();
    }

    $diff = self::renderPatch($patch);
    try {
      $this->gitPipeCommand($diff, 'am', '--keep-non-patch', '--keep-cr');
    } catch (ShipItRepoGITException $e) {
      // If we are trying to git am on a non-git repo, for example
      $this->gitCommand('am', '--abort');
      throw $e;
    } catch (ShipItRepoException $e) {
      $this->gitCommand('am', '--abort');
      throw $e;
    } catch (ShipItShellCommandException $e) {
      $this->gitCommand('am', '--abort');
      throw $e;
    }

    $submodules = $this->getSubmodules();
    foreach ($submodules as $submodule) {
      // If a submodule has changed, then we need to actually update to the
      // new version. + before commit hash represents changed submdoule. Make
      // sure there is no leading whitespace that comes back when we get the
      // status since the first character will tell us whether submodule
      // changed.
      $sm_status = Str\trim_left(
        $this->gitCommand('submodule', 'status', $submodule['path']),
      );
      if ($sm_status === '') {
        // If the path exists, we know we are adding a submodule.
        $full_path = $this->getPath().'/'.$submodule['path'];
        $sha = Str\trim(Str\slice(
          \file_get_contents($full_path),
          Str\length('Subproject commit '),
        ));
        $this->gitCommand('rm', $submodule['path']);
        $this->gitCommand(
          'submodule',
          'add',
          '-f',
          '--name',
          $submodule['name'],
          $submodule['url'],
          $submodule['path'],
        );
        (new ShipItShellCommand($full_path, 'git', 'checkout', $sha))
          ->runSynchronously();
        $this->gitCommand('add', $submodule['path']);
        // Preserve any whitespace in the .gitmodules file.
        $this->gitCommand('checkout', 'HEAD', '.gitmodules');
        $this->gitCommand('commit', '--amend', '--no-edit');
      } else if ($sm_status[0] === '+') {
        $this->gitCommand(
          'submodule',
          'update',
          '--recursive',
          $submodule['path'],
        );
      }
    }
    // DANGER ZONE!  Cleanup any removed submodules.
    $this->gitCommand('clean', '-f', '-f', '-d');

    return $this->getHEADSha();
  }

  protected function gitPipeCommand(?string $stdin, string ...$args): string {
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    if (!\file_exists("{$this->path}/.git")) {
      throw new ShipItRepoGITException($this, $this->path." is not a GIT repo");
    }

    $command = (new ShipItShellCommand($this->path, 'git', ...$args))
      ->setEnvironmentVariables(ImmMap {
        'GIT_CONFIG_NOSYSTEM' => '1',
        // GIT_CONFIG_NOGLOBAL was dropped because it was possible to use
        // HOME instead - see commit 8f323c00dd3c9b396b01a1aeea74f7dfd061bb7f in
        // git itself.
        'HOME' => $this->fakeHome->getPath(),
      });
    if ($stdin !== null) {
      $command->setStdIn($stdin);
    }
    return $command->runSynchronously()->getStdOut();
  }

  protected function gitCommand(string ...$args): string {
    return $this->gitPipeCommand(null, ...$args);
  }

  public static function cloneRepo(string $origin, string $path): void {
    invariant(
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      !\file_exists($path),
      '%s already exists, cowardly refusing to overwrite',
      $path,
    );

    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    $parent_path = \dirname($path);
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    if (!\file_exists($parent_path)) {
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      \mkdir($parent_path, 0755, /* recursive = */ true);
    }

    if (ShipItRepo::$verbose & ShipItRepo::VERBOSE_FETCH) {
      ShipItLogger::err("** Cloning %s to %s\n", $origin, $path);
    }

    (
      new ShipItShellCommand($parent_path, 'git', 'clone', $origin, $path)
    )->runSynchronously();
  }

  <<__Override>>
  public function clean(): void {
    $this->gitCommand('clean', '-x', '-f', '-f', '-d');
  }

  <<__Override>>
  public function pushLfs(string $pull_endpoint, string $push_endpoint): void {
    invariant(
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      \file_exists($this->getPath().'/.gitattributes'),
      '.gitattributes not exists, cowardly refusing to pull lfs',
    );
    // ignore .lfsconfig. otherwise this would interfere
    // with the downstream consumer.
    invariant(
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      !\file_exists($this->getPath().'/.lfsconfig'),
      '.lfsconfig exists, needs to strip it in your config',
    );
    $this->gitCommand('lfs', 'install', '--local');
    $this->gitCommand('config', '--local', 'lfs.url', $pull_endpoint);
    $this->gitCommand('config', '--local', 'lfs.fetchrecentcommitsdays', '7');
    $this->gitCommand('lfs', 'fetch', '--recent');
    $this->gitCommand('config', '--local', 'lfs.pushurl', $push_endpoint);
    $this->gitCommand('lfs', 'push', 'origin', $this->branch);
  }

  <<__Override>>
  public function pull(): void {
    if (ShipItRepo::$verbose & ShipItRepo::VERBOSE_FETCH) {
      ShipItLogger::err("** Updating checkout in %s\n", $this->path);
    }

    try {
      $this->gitCommand('am', '--abort');
    } catch (ShipItShellCommandException $_e) {
      // ignore
    }

    $this->gitCommand('fetch', 'origin');
    $this->gitCommand('reset', '--hard', 'origin/'.$this->branch);
  }

  <<__Override>>
  public function getOrigin(): string {
    return Str\trim($this->gitCommand('remote', 'get-url', 'origin'));
  }

  public function push(): void {
    $this->gitCommand('push', 'origin', 'HEAD:'.$this->branch);
  }

  public function export(
    ImmSet<string> $roots,
    ?string $rev = null,
  ): shape('tempDir' => ShipItTempDir, 'revision' => string) {
    if ($rev === null) {
      $rev = Str\trim($this->gitCommand('rev-parse', 'HEAD'));
    }

    $command = Vector {
      'archive',
      '--format=tar',
      $rev,
    };
    $command->addAll($roots);
    $tar = $this->gitCommand(...$command);

    $dest = new ShipItTempDir('git-export');
    (new ShipItShellCommand($dest->getPath(), 'tar', 'x'))->setStdIn($tar)
      ->runSynchronously();

    // If we have any submodules, we'll need to set them up manually.
    foreach ($this->getSubmodules($roots) as $submodule) {
      $status = $this->gitCommand('submodule', 'status', $submodule['path']);
      $sha = $status
        // Strip any -, +, or U at the start of the status (see the man page for
        // git-submodule).
        /* HH_IGNORE_ERROR[2049] __PHPStdLib */
        /* HH_IGNORE_ERROR[4107] __PHPStdLib */
        |> \preg_replace('@^[\-\+U]@', '', $$)
        /* HH_IGNORE_ERROR[2049] __PHPStdLib */
        /* HH_IGNORE_ERROR[4107] __PHPStdLib */
        |> \explode(' ', $$)[0];
      $dest_submodule_path = $dest->getPath().'/'.$submodule['path'];
      // This removes the empty directory for the submodule that gets created
      // by the git-archive command.
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      \rmdir($dest_submodule_path);
      // This will setup a file that looks just like how git stores submodules.
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      \file_put_contents($dest_submodule_path, 'Subproject commit '.$sha);
    }

    return shape('tempDir' => $dest, 'revision' => $rev);
  }

  protected function getHEADSha(): string {
    return Str\trim($this->gitCommand('log', '-1', "--pretty=format:%H"));
  }

  private function getSubmodules(
    ?ImmSet<string> $roots = null,
  ): ImmVector<self::TSubmoduleSpec> {
    // The gitmodules file is in the repo root, so if this application is for
    // a set of source roots that does not contain the entire repository then
    // there are no relevant submodules.
    if ($roots !== null && $roots->count() > 0 && !$roots->contains('')) {
      return ImmVector {};
    }
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    if (!\file_exists($this->getPath().'/.gitmodules')) {
      return ImmVector {};
    }
    $configs = $this->gitCommand('config', '-f', '.gitmodules', '--list');
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    $configs = (new Map(\parse_ini_string($configs)))
      ->filterWithKey(($key, $_) ==> {
        return Str\slice($key, 0, 10) === 'submodule.' &&
          (Str\slice($key, -5) === '.path' || Str\slice($key, -4) === '.url');
      });
    $names = $configs->keys()
      ->filter($key ==> Str\slice($key, -4) === '.url')
      ->map($key ==> Str\slice($key, 10, Str\length($key) - 10 - 4))
      ->toImmSet();
    return $names->values()
      ->map(
        $name ==> shape(
          'name' => $name,
          'path' => $configs['submodule.'.$name.'.path'],
          'url' => $configs['submodule.'.$name.'.url'],
        ),
      )
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      ->filter($config ==> \file_exists($this->getPath().'/'.$config['path']))
      ->toImmVector();
  }
}
