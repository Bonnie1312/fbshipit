<?hh // strict
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/bliil915
 */
namespace Facebook\ShipIt;

use namespace HH\Lib\{C, Str};

final class ShipItRepoHGException extends ShipItRepoException {}

/**
 * HG specialization of ShipItRepo
 */
class ShipItRepoHG
  extends ShipItRepo
  implements ShipItDestinationRepo, ShipItSourceRepo {
  private ?string $branch;
  const string COMMIT_SEPARATOR = '-~-~-~';

  public function __construct(string $path, string $branch): void {
    parent::__construct($path, $branch);

    try {
      // $this->path will be set by here as it is the first thing to
      // set on the constructor call. So it can be used in hgCommand, etc.
      $this->hgCommand('root');
    } catch (ShipItRepoException $_ex) {
      throw new ShipItRepoHGException($this, "{$this->path} is not a HG repo");
    }
  }

  <<__Override>>
  public function setBranch(string $branch): bool {
    $this->branch = $branch;
    return true;
  }

  <<__Override>>
  public function updateBranchTo(string $base_rev): void {
    $branch = $this->branch;
    if ($branch === null) {
      throw new ShipItRepoHGException($this, 'setBranch must be called first.');
    }
    $this->hgCommand('bookmark', '--force', '--rev', $base_rev, $branch);
    $this->hgCommand('update', $branch);
  }

  <<__Override>>
  public function getHeadChangeset(): ?ShipItChangeset {
    $branch = $this->branch;
    if ($branch === null) {
      throw new ShipItRepoHGException($this, "setBranch must be called first.");
    }
    $log = $this->hgCommand(
      'log',
      '--limit',
      '1',
      '-r',
      $branch,
      '--template',
      '{node}\\n',
    );
    $log = Str\trim($log);
    if ($log === '') {
      return null;
    }
    if (Str\length($log) !== 40) {
      throw new ShipItRepoHGException(
        $this,
        "{$log} doesn't look like a valid"." hg changeset id",
      );
    }
    return $this->getChangesetFromID($log);
  }

  public function findNextCommit(
    string $revision,
    ImmSet<string> $roots,
  ): ?string {
    $branch = $this->branch;
    if ($branch === null) {
      throw new ShipItRepoHGException($this, "setBranch must be called first.");
    }
    $log = $this->hgCommand(
      'log',
      '--limit',
      '1',
      '-r',
      "({$revision}::{$branch}) - {$revision}",
      '--template',
      '{node}\\n',
      ...$roots
    );
    $log = Str\trim($log);
    if ($log === '') {
      return null;
    }
    if (Str\length($log) !== 40) {
      throw new ShipItRepoHGException(
        $this,
        "{$log} doesn't look like a valid"." hg changeset id",
      );
    }
    return $log;
  }

  public function findLastSourceCommit(ImmSet<string> $roots): ?string {
    $log = $this->hgCommand(
      'log',
      '--limit',
      '1',
      '--keyword',
      'fbshipit-source-id:',
      '--template',
      '{desc}',
      ...$roots
    );
    $log = Str\trim($log);
    $matches = null;
    if (
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      !\preg_match_all(
        '/^ *fbshipit-source-id: ?(?<commit>[a-z0-9]+)$/m',
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

  public function commitPatch(ShipItChangeset $patch): string {
    if ($patch->getDiffs()->count() === 0) {
      // This is an empty commit, which `hg patch` does not handle properly.
      $this->hgCommand(
        '--config',
        'ui.allowemptycommit=True',
        'commit',
        '--user',
        $patch->getAuthor(),
        '--date',
        /* HH_IGNORE_ERROR[2049] __PHPStdLib */
        /* HH_IGNORE_ERROR[4107] __PHPStdLib */
        \date('c', $patch->getTimestamp()),
        '-m',
        self::getCommitMessage($patch),
      );
    } else {
      $diff = self::renderPatch($patch);
      $this->hgPipeCommand($diff, 'patch', '-');
    }
    $id = $this->getChangesetFromID('.')?->getID();
    invariant($id !== null, 'Unexpeceted null SHA!');
    return $id;
  }

  public static function renderPatch(ShipItChangeset $patch): string {
    // Mon Sep 17 is a magic date used by format-patch to distinguish from real
    // mailboxes. cf. https://git-scm.com/docs/git-format-patch
    $commit_message = self::getCommitMessage($patch);
    $ret = "From {$patch->getID()} Mon Sep 17 00:00:00 2001\n".
      "From: {$patch->getAuthor()}\n".
      "Date: ".
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      \date('r', $patch->getTimestamp()).
      "\n".
      "Subject: [PATCH] {$commit_message}\n---\n\n";
    foreach ($patch->getDiffs() as $diff) {
      $path = $diff['path'];
      $body = $diff['body'];

      $ret .= "diff --git a/{$path} b/{$path}\n{$body}";
    }
    $ret .= "--\n1.7.9.5\n";
    return $ret;
  }

  public function push(): void {
    $branch = $this->branch;
    if ($branch === null) {
      throw new ShipItRepoHGException($this, 'setBranch must be called first.');
    }
    $this->hgCommand('push', '--branch', $branch);
  }

  /*
   * Generator yielding patch sections of the diff blocks (individually).
   */
  private static function parseHgRegions(string $patch): Iterator<string> {
    $contents = '';
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    foreach (\explode("\n", $patch) as $line) {
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      $line = \preg_replace('/(\r\n|\n)/', "\n", $line);

      if (
        /* HH_IGNORE_ERROR[2049] __PHPStdLib */
        /* HH_IGNORE_ERROR[4107] __PHPStdLib */
        \preg_match(
          '@^diff --git( ([ab]/(.*?)|/dev/null)){2}@',
          Str\trim_right($line),
        ) &&
        $contents !== ''
      ) {
        yield $contents;
        $contents = '';
      }
      $contents .= $line."\n";
    }
    if ($contents !== '') {
      yield $contents;
    }
  }

  private static function parseHeader(string $header): ShipItChangeset {
    $changeset = new ShipItChangeset();

    $subject = null;
    $message = '';
    $past_separator = false;
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    foreach (\explode("\n", $header) as $line) {
      if (!$past_separator && $line === self::COMMIT_SEPARATOR) {
        $past_separator = true;
        continue;
      }
      if (Str\length($line) === 0) {
        $message .= "\n";
        continue;
      }
      if ($line[0] === '#' && !$past_separator) {
        /* HH_IGNORE_ERROR[2049] __PHPStdLib */
        /* HH_IGNORE_ERROR[4107] __PHPStdLib */
        if (!\strncasecmp($line, '# User ', 7)) {
          $changeset = $changeset->withAuthor(Str\slice($line, 7));
          /* HH_IGNORE_ERROR[2049] __PHPStdLib */
          /* HH_IGNORE_ERROR[4107] __PHPStdLib */
        } else if (!\strncasecmp($line, '# Date ', 7)) {
          $changeset = $changeset->withTimestamp((int)Str\slice($line, 7));
        }
        // Ignore anything else in the envelope
        continue;
      }
      if ($subject === null) {
        $subject = $line;
        continue;
      }
      $message .= "{$line}\n";
    }

    return $changeset
      ->withSubject((string)$subject)
      ->withMessage(Str\trim($message));
  }

  public function getNativePatchFromID(string $revision): string {
    return $this->hgCommand(
      'log',
      '--config',
      'diff.git=True',
      '-r',
      $revision,
      '--encoding',
      'UTF-8',
      '--template',
      '{diff()}',
    );
  }

  public function getNativeHeaderFromID(string $revision): string {
    return $this->hgCommand(
      'log',
      '--config',
      'diff.git=True',
      '-r',
      $revision,
      '--encoding',
      'UTF-8',
      '--template',
      '# User {author}
# Date {date}
# Node ID {node}
'.self::COMMIT_SEPARATOR.'
{desc}',
    );
  }

  public function getChangesetFromID(string $revision): ?ShipItChangeset {
    $header = $this->getNativeHeaderFromID($revision);
    $patch = $this->getNativePatchFromID($revision);
    $changeset = $this->getChangesetFromNativePatch($revision, $header, $patch);
    return $changeset;
  }

  private function getChangesetFromNativePatch(
    string $revision,
    string $header,
    string $patch,
  ): ShipItChangeset {
    $changeset = self::getChangesetFromExportedPatch($header, $patch);
    // we need to have plain diffs for each file, and rename/copy from
    // breaks this, and we can't turn it off in hg.
    //
    // for example, if the change to 'proprietary/foo.cpp' is removed,
    // but 'public/foo.cpp' is not, this breaks:
    //
    //   rename from proprietary/foo.cpp to public/foo.cpp
    //
    // If we have any matching files, re-create their diffs using git, which
    // will do full diffs for both sides of the copy/rename.
    $matches = darray[];
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    \preg_match_all(
      '/^(?:rename|copy) (?:from|to) (?<files>.+)$/m',
      $patch,
      inout $matches,
      \PREG_PATTERN_ORDER,
    );
    $has_rename_or_copy = new ImmSet($matches['files']);
    $has_mode_change = $changeset
      ->getDiffs()
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      ->filter($diff ==> \preg_match('/^old mode/m', $diff['body']) === 1)
      ->map($diff ==> $diff['path'])
      ->toImmSet();

    $needs_git = $has_rename_or_copy
      ->concat($has_mode_change)
      ->toImmSet();

    if ($needs_git) {
      $diffs = $changeset
        ->getDiffs()
        ->filter($diff ==> !$needs_git->contains($diff['path']))
        ->toVector();
      $diffs->addAll($this->makeDiffsUsingGit($revision, $needs_git));
      $changeset = $changeset->withDiffs($diffs->toImmVector());
    }

    return $changeset->withID($revision);
  }

  <<__Override>>
  public static function getDiffsFromPatch(
    string $patch,
  ): ImmVector<ShipItDiff> {
    $diffs = Vector {};
    foreach (self::parseHgRegions($patch) as $region) {
      $diff = self::parseDiffHunk($region);
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
    $changeset = self::parseHeader($header);
    return $changeset->withDiffs(self::getDiffsFromPatch($patch));
  }

  protected function hgPipeCommand(?string $stdin, string ...$args): string {
    // Some server-side commands will inexplicitly fail, and then succeed the
    // next time they are ran.  There are a some, however, that we never want
    // to re-run because we'll lose error messages as a result.
    switch ((new ImmVector($args))->firstValue() ?? '') {
      case 'patch':
        $retry_count = 0;
        break;
      default:
        $retry_count = 1;
    }

    $command = (new ShipItShellCommand($this->path, 'hg', ...$args))
      ->setEnvironmentVariables(ImmMap {
        'HGPLAIN' => '1',
      })
      ->setRetries($retry_count);
    if ($stdin !== null) {
      $command->setStdIn($stdin);
    }
    return $command->runSynchronously()->getStdOut();
  }

  protected function hgCommand(string ...$args): string {
    return $this->hgPipeCommand(null, ...$args);
  }

  <<__Override>>
  public function clean(): void {
    $this->hgCommand('purge', '--all');
  }

  <<__Override>>
  public function pushLfs(
    string $_pull_endpoint,
    string $_push_endpoint,
  ): void {
    throw new ShipItRepoHGException($this, "push lfs not implemented for hg");
  }

  <<__Override>>
  public function pull(): void {
    if (ShipItRepo::$verbose & ShipItRepo::VERBOSE_FETCH) {
      ShipItLogger::err("** Updating checkout in %s\n", $this->path);
    }
    $this->hgCommand('pull');
  }

  <<__Override>>
  public function getOrigin(): string {
    return Str\trim($this->hgCommand('config', 'paths.default'));
  }

  private function makeDiffsUsingGit(
    string $rev,
    ImmSet<string> $files,
  ): ImmVector<ShipItDiff> {
    $tempdir = new ShipItTempDir('git-wd');
    $path = $tempdir->getPath();

    $this->checkoutFilesAtRevToPath($files, $rev.'^', $path.'/a');
    $this->checkoutFilesAtRevToPath($files, $rev, $path.'/b');

    $result = (
      new ShipItShellCommand(
        $path,
        'git',
        'diff',
        '--binary',
        '--no-prefix',
        '--no-renames',
        'a',
        'b',
      )
    )->setNoExceptions()->runSynchronously();

    invariant(
      $result->getExitCode() === 1,
      'git diff exited with %d, which means no changes; expected 1, '.
      'which means non-empty diff.',
      $result->getExitCode(),
    );
    $patch = $result->getStdOut();

    $diffs = Vector {};
    foreach (ShipItUtil::parsePatch($patch) as $hunk) {
      $diff = self::parseDiffHunk($hunk);
      if ($diff !== null) {
        $diffs[] = $diff;
      }
    }
    return $diffs->toImmVector();
  }

  private function checkoutFilesAtRevToPath(
    ImmSet<string> $files,
    string $rev,
    string $path,
  ): void {
    /* Use a list of patterns from a file (/dev/stdin) instead
     * of specifying on the command line - otherwise, we can
     * generate a command that is larger than the maximum length
     * allowed by the system, so, exec() won't actually execute.
     *
     * In the case of zero files passed, assume that means we're exporting
     * the root, otherwise archive will fail.
     *
     * Example diff:
     *   rFBSed54f611dc0aebe17010b3416e64549d95ee3a49
     *   ... which is https://github.com/facebook/nuclide/commit/2057807d2653dd1af359f44f658eadac6eaae34b
     */
    if ($files->count() === 0) {
      $files = ImmSet {'.'};
    }
    $patterns = $files->map($file ==> 'path:'.$file);
    $patterns = Str\join($patterns, "\n");

    // Prefetch is needed for reasonable performance with the remote file
    // log extension
    $lock = $this->getSharedLock()->getExclusive();
    try {
      $this->hgPipeCommand(
        $patterns,
        'prefetch',
        '-r',
        $rev,
        'listfile:/dev/stdin',
      );
    } catch (ShipItShellCommandException $_e) {
      // ignore, not all repos are shallow
    } finally {
      $lock->release();
    }

    $this->hgPipeCommand(
      $patterns,
      'archive',
      '--config',
      'ui.archivemeta=False',
      '-r',
      $rev,
      '-I',
      'listfile:/dev/stdin',
      $path,
    );
  }

  public function export(
    ImmSet<string> $roots,
    ?string $rev = null,
  ): shape('tempDir' => ShipItTempDir, 'revision' => string) {
    $branch = $this->branch;
    if ($branch === null) {
      throw new ShipItRepoHGException($this, 'setBranch must be called first.');
    }
    if ($rev === null) {
      $rev = $this->hgCommand('log', '-r', $branch, '-T', '{node}');
    }

    $temp_dir = new ShipItTempDir('hg-export');
    $this->checkoutFilesAtRevToPath($roots, $rev, $temp_dir->getPath());

    return shape('tempDir' => $temp_dir, 'revision' => $rev);
  }
}
