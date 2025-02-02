<?hh // strict
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/wrfvw1gs
 */
namespace Facebook\ShipIt;

use namespace HH\Lib\Str;

<<\Oncalls('open_source')>>
final class NewlinesTest extends ShellTest {
  const UNIX_TXT = "foo\nbar\nbaz\n";
  const WINDOWS_TXT = "foo\r\nbar\r\nbaz\r\n";

  public function testTestData(): void {
    \expect(Str\length(self::WINDOWS_TXT))->toBeSame(
      Str\length(self::UNIX_TXT) + 3,
    );
  }

  public function testMercurialSource(): void {
    $temp_dir = new ShipItTempDir('mercurial-newline-test');

    $this->createTestFiles($temp_dir);
    $this->initMercurialRepo($temp_dir);

    $this->execSteps(
      $temp_dir->getPath(),
      vec['hg', 'commit', '-Am', 'add files'],
    );

    $repo = new ShipItRepoHG($temp_dir->getPath(), 'master');
    $changeset = $repo->getChangesetFromID('.');
    $changeset = \expect($changeset)->toNotBeNull();

    $this->assertContainsCorrectNewLines($changeset);
    $this->assertCreatesCorrectNewLines($changeset);
  }

  public function testGitSource(): void {
    $temp_dir = new ShipItTempDir('git-newline-test');

    $this->createTestFiles($temp_dir);
    $this->initGitRepo($temp_dir);

    $this->execSteps(
      $temp_dir->getPath(),
      vec['git', 'add', '.'],
      vec['git', 'commit', '-m', 'add files'],
    );

    $repo = new ShipItRepoGIT($temp_dir->getPath(), 'master');
    $changeset = $repo->getChangesetFromID('HEAD');
    $changeset = \expect($changeset)->toNotBeNull();

    $this->assertContainsCorrectNewLines($changeset);
    $this->assertCreatesCorrectNewLines($changeset);
  }

  private function createTestFiles(ShipItTempDir $temp_dir): void {
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    \file_put_contents($temp_dir->getPath().'/unix.txt', self::UNIX_TXT);
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    \file_put_contents($temp_dir->getPath().'/windows.txt', self::WINDOWS_TXT);
  }

  private function assertContainsCorrectNewLines(
    ShipItChangeset $changeset,
  ): void {
    $map = Map {};
    foreach ($changeset->getDiffs() as $diff) {
      $map[$diff['path']] = $diff['body'];
    }
    \expect($map['unix.txt'])->toContainSubstring("\n");
    \expect($map['windows.txt'])->toContainSubstring("\r\n");
    \expect($map['unix.txt'])->toNotContainSubstring("\r\n");
  }

  private function initGitRepo(ShipItTempDir $temp_dir): void {
    $this->execSteps($temp_dir->getPath(), vec['git', 'init']);
    $this->configureGit($temp_dir);
  }

  private function initMercurialRepo(ShipItTempDir $temp_dir): void {
    $this->execSteps($temp_dir->getPath(), vec['hg', 'init']);
    $this->configureHg($temp_dir);
  }

  private function assertCreatesCorrectNewLines(
    ShipItChangeset $changeset,
  ): void {
    $git_dir = new ShipItTempDir('newline-output-test-git');
    $this->initGitRepo($git_dir);
    $hg_dir = new ShipItTempDir('newline-output-test-hg');
    $this->initMercurialRepo($hg_dir);
    $repos = ImmVector {
      new ShipItRepoGIT($git_dir->getPath(), '--orphan=master'),
      new ShipItRepoHG($hg_dir->getPath(), 'master'),
    };

    foreach ($repos as $repo) {
      $repo->commitPatch($changeset);

      \expect(\file_get_contents($repo->getPath().'/unix.txt'))->toBeSame(
        self::UNIX_TXT,
        'Unix test file',
      );
      \expect(\file_get_contents($repo->getPath().'/windows.txt'))->toBeSame(
        self::WINDOWS_TXT,
        'Windows text file',
      );
    }
  }
}
