<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\daedalus_ai\Attachments;
use Drupal\file\FileInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The attachment routes over a real multipart upload.
 *
 * This is the request entry's proof: FileLane::fromRequest() moves through
 * PHP's move_uploaded_file(), which refuses anything that is not a real PHP
 * upload, so the attach route cannot run inside a Kernel test. The site is
 * the AI umbrella recipe's product (the fixture trait's composition, so the
 * editor role carries 'use ai assistant' exactly as shipped) and the whole
 * scenario rides one method: an install per method is the dominant cost.
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
final class AttachRouteTest extends BrowserTestBase {

  use DaedalusFixtureTrait;

  /**
   * A thread id in the shape ai_assistant_api mints.
   */
  private const THREAD = 'assistant_thread_2_0';

  /**
   * {@inheritdoc}
   */
  protected function installRecipePath(): string {
    return dirname($this->root) . '/recipes/daedalus_recipe/ai';
  }

  /**
   * Attach saves through the lane and holds; release lets go; gates hold.
   */
  public function testAttachAndRelease(): void {
    $this->loginAsEditor();
    $token = $this->csrfToken();
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';

    // A file attaches: saved temporary under the attachment directory, held
    // for the thread, named by its saved filename.
    $response = $this->post('daedalus-ai/attach', $token, [
      ['name' => 'file', 'contents' => $svg, 'filename' => 'hero.svg'],
      ['name' => 'thread_id', 'contents' => self::THREAD],
    ]);
    $this->assertSame(200, $response['status'], $response['body']);
    $answer = Json::decode($response['body']);
    $this->assertSame('hero.svg', $answer['name']);
    $this->assertSame('image/svg+xml', $answer['mime']);
    $hero = $this->heldFile('hero.svg');
    $this->assertSame((int) $hero->getSize(), $answer['size']);
    // Held: core makes a used file permanent, which is what keeps GC off it.
    $this->assertTrue($hero->isPermanent());
    $this->assertStringStartsWith(Attachments::DIRECTORY . '/', $hero->getFileUri());
    $this->assertSame(['daedalus_ai' => ['thread' => [self::THREAD => '1']]], $this->usage($hero));

    // A second file of the same name is renamed by the lane and the answer
    // carries the name that resolves.
    $response = $this->post('daedalus-ai/attach', $token, [
      ['name' => 'file', 'contents' => $svg, 'filename' => 'hero.svg'],
      ['name' => 'thread_id', 'contents' => self::THREAD],
    ]);
    $this->assertSame(200, $response['status'], $response['body']);
    $second = Json::decode($response['body'])['name'];
    $this->assertNotSame('hero.svg', $second);
    $this->assertNotSame($hero->uuid(), $this->heldFile($second)->uuid());

    // The lane's refusals and the request contract are 400s naming the
    // reason; the gates are 403s.
    $response = $this->post('daedalus-ai/attach', $token, [
      ['name' => 'file', 'contents' => 'MZ', 'filename' => 'tool.exe'],
      ['name' => 'thread_id', 'contents' => self::THREAD],
    ]);
    $this->assertSame(400, $response['status']);
    $this->assertStringContainsString('extension', $response['body']);
    $response = $this->post('daedalus-ai/attach', $token, [
      ['name' => 'thread_id', 'contents' => self::THREAD],
    ]);
    $this->assertSame(400, $response['status']);
    $this->assertStringContainsString('no file', $response['body']);
    $response = $this->post('daedalus-ai/attach', $token, [
      ['name' => 'file', 'contents' => $svg, 'filename' => 'hero.svg'],
    ]);
    $this->assertSame(400, $response['status']);
    $this->assertStringContainsString('thread_id', $response['body']);
    $response = $this->post('daedalus-ai/attach', 'not-the-token', [
      ['name' => 'file', 'contents' => $svg, 'filename' => 'hero.svg'],
      ['name' => 'thread_id', 'contents' => self::THREAD],
    ]);
    $this->assertSame(403, $response['status']);

    // Release, as the chat menu posts it: both files let go.
    $response = $this->post('daedalus-ai/attachments/release', $token, NULL, ['thread_id' => self::THREAD]);
    $this->assertSame(200, $response['status'], $response['body']);
    $this->assertSame(['released' => 2], Json::decode($response['body']));
    $this->assertSame([], $this->usage($hero));
    $storage = $this->container->get('entity_type.manager')->getStorage('file');
    $storage->resetCache([$hero->id()]);
    $this->assertTrue($storage->load($hero->id())->isTemporary());
    $this->assertNull($this->container->get('daedalus_blueprint.dialect_alias_map')->resolveFile(self::THREAD, 'hero.svg'));

    // Anonymous never reaches either route.
    $this->drupalLogout();
    $response = $this->post('daedalus-ai/attach', $this->csrfToken(), [
      ['name' => 'file', 'contents' => $svg, 'filename' => 'hero.svg'],
      ['name' => 'thread_id', 'contents' => self::THREAD],
    ]);
    $this->assertSame(403, $response['status']);
  }

  /**
   * Posts to a route as the browser session, multipart or JSON.
   *
   * @return array{status: int, body: string}
   *   The response status and body.
   */
  private function post(string $path, string $token, ?array $multipart, ?array $json = NULL): array {
    $options = [
      'headers' => ['X-CSRF-Token' => $token],
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
    ];
    if ($multipart !== NULL) {
      $options['multipart'] = $multipart;
    }
    else {
      $options['json'] = $json;
    }
    $response = $this->getHttpClient()->request('POST', $this->buildUrl($path), $options);
    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
  }

  /**
   * The session's CSRF token, as the page builder's JS fetches it.
   */
  private function csrfToken(): string {
    $this->drupalGet('session/token');
    return $this->getSession()->getPage()->getContent();
  }

  /**
   * The file held under a name in the thread, resolved as its owner would.
   */
  private function heldFile(string $name): FileInterface {
    $uuid = $this->container->get('daedalus_blueprint.dialect_alias_map')->resolveFile(self::THREAD, $name);
    $this->assertNotNull($uuid, "$name is held");
    $storage = $this->container->get('entity_type.manager')->getStorage('file');
    $storage->resetCache();
    $files = $storage->loadByProperties(['uuid' => $uuid]);
    $file = reset($files);
    $this->assertInstanceOf(FileInterface::class, $file);
    return $file;
  }

  /**
   * The file's usage as core lists it.
   */
  private function usage(FileInterface $file): array {
    return $this->container->get('file.usage')->listUsage($file);
  }

}
