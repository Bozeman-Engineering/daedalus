<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\daedalus_ui\EditorSettings;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The media upload routes take a tokened POST and make media only by right.
 *
 * Two routes take a file dropped from the desktop: the page drop
 * (new_media: the file is saved, then a block type is resolved for it from
 * a stored association or the chooser form) and the drop on a media
 * reference (replace_media: the file becomes media of the named bundle and
 * an update_element writes the reference). Both save a file and create
 * media, so:
 *
 * - a GET is not a way in (405) and a POST without the session token in
 *   the X-CSRF-Token header is refused (403) and saves nothing;
 * - the actor is in Edit Mode's audience and may update the host, and
 *   media is created only for an actor who may create that bundle;
 * - a page drop takes media kinds only (a text file is refused though the
 *   file lane as a whole accepts one), and a bundle whose source is not a
 *   file is refused;
 * - the file is temporary until a media entity holds it;
 * - the chooser form submits to its own form route, guarded by core's
 *   form token, never back to the upload route.
 *
 * The token check and the route gates live in the route table and the
 * session, which only a real request crosses, so this is a browser-less
 * functional test on the recipe's product as its editor; the whole
 * scenario rides one method because an install per method is the dominant
 * cost.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
final class MediaUploadRouteTest extends BrowserTestBase {

  use DaedalusFixtureTrait;
  use MediaTypeCreationTrait;

  /**
   * A 1x1 PNG.
   */
  private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

  /**
   * What the client's upload URL carries beside the path.
   */
  private const QUERY = ['navigationMode' => 'edit', '_wrapper_format' => 'drupal_ajax'];

  /**
   * The files and media the recipe's product ships with.
   *
   * @var array{file: list<int>, media: list<int>}
   */
  private array $shipped = ['file' => [], 'media' => []];

  /**
   * The routes' gates, the lane's narrowing and the file's permanence.
   */
  public function testUploadRoutesNeedPostTokenAndCreateAccess(): void {
    $editor = $this->loginAsEditor();
    $token = $this->csrfToken();
    $this->shipped = ['file' => $this->ids('file', 'fid'), 'media' => $this->ids('media', 'mid')];
    $page = Node::create(['type' => 'page', 'title' => 'Upload page']);
    $page->save();
    $plain = Node::create(['type' => 'plain', 'title' => 'Upload plain']);
    $plain->save();

    $new = 'daedalus-ui/new-media/node/' . $page->id() . '/full';
    $replace = 'daedalus-ui/replace-media/node/' . $plain->id() . '/full/field_media/';
    $png = ['shot.png', base64_decode(self::PNG)];

    // A GET is not a way in.
    $this->assertSame(405, $this->send('GET', $new, $token)['status']);
    $this->assertSame(405, $this->send('GET', $replace . 'image', $token)['status']);

    // A POST without the token is refused and saves nothing.
    $this->assertSame(403, $this->send('POST', $new, NULL, $png)['status']);
    $this->assertSame(403, $this->send('POST', $new, 'not-the-token', $png)['status']);
    $this->assertSame(403, $this->send('POST', $replace . 'image', NULL, $png)['status']);
    $this->assertSame([], $this->fileIds(), 'A refused request saved no file.');
    $this->assertSame([], $this->mediaIds(), 'A refused request created no media.');

    // The page drop takes media kinds only: a text file, which the lane
    // accepts from the AI's doors, is refused here. So is no file at all.
    $response = $this->send('POST', $new, $token, ['notes.txt', 'Just words.']);
    $this->assertSame(400, $response['status'], $response['body']);
    $response = $this->send('POST', $new, $token, ['notes.md', '# Words']);
    $this->assertSame(400, $response['status'], $response['body']);
    $this->assertSame(400, $this->send('POST', $new, $token)['status']);
    $this->assertSame([], $this->fileIds(), 'A refused kind saved no file.');

    // A media kind with no stored association: the file is saved, held by
    // nothing yet and so temporary, and the answer is the chooser dialog.
    $response = $this->send('POST', $new, $token, $png);
    $this->assertSame(200, $response['status'], $response['body']);
    $commands = Json::decode($response['body']);
    $this->assertIsArray($commands);
    $this->assertArrayNotHasKey('resolution', $commands);
    $fids = $this->fileIds();
    $this->assertCount(1, $fids);
    $this->assertTrue($this->file($fids[0])->isTemporary(), 'An uploaded file no media holds is temporary.');
    $this->assertSame([], $this->mediaIds(), 'The upload alone creates no media.');

    // The chooser submits to its own form route, not back to the upload.
    $dialog = '';
    $submit_url = NULL;
    foreach ($commands as $command) {
      if (($command['command'] ?? '') === 'openDialog') {
        $dialog = (string) $command['data'];
      }
      if (($command['command'] ?? '') === 'settings') {
        foreach ($command['settings']['ajax'] ?? [] as $ajax) {
          if (($ajax['selector'] ?? '') === '#file-association-submit') {
            $submit_url = $ajax['url'];
          }
        }
      }
    }
    $this->assertStringContainsString('name="file_extension_association"', $dialog, $response['body']);
    $this->assertNotNull($submit_url, 'The chooser names where it submits.');
    $this->assertStringContainsString('/daedalus-layout/media-file-association/node/' . $page->id(), $submit_url);
    $this->assertStringNotContainsString('new-media', $submit_url);
    $fields = [
      'form_id' => 'daedalus_layout_media_block_file_association',
      'form_build_id' => $this->hiddenValue($dialog, 'form_build_id'),
      'form_token' => $this->hiddenValue($dialog, 'form_token'),
      'file_extension_association' => 'image',
      '_triggering_element_name' => 'op',
      '_triggering_element_value' => 'Save',
    ];

    // Without core's form token the submission chooses nothing.
    $response = $this->submit($submit_url, ['form_token' => 'forged'] + $fields);
    $this->assertNull($this->chosen($response['body']), $response['body']);
    $this->assertSame([], $this->mediaIds(), 'A forged form submission created no media.');

    // With it, the choice comes back as the resolution; the media now
    // holds the file, which is what makes it permanent.
    $response = $this->submit($submit_url, $fields);
    $this->assertSame(200, $response['status'], $response['body']);
    $resolution = $this->chosen($response['body']);
    $this->assertNotNull($resolution, $response['body']);
    $this->assertSame('image', $resolution['block_type']);
    $mids = $this->mediaIds();
    $this->assertSame([$resolution['media_target_id']], $mids);
    $this->assertSame('image', Media::load($mids[0])->bundle());
    $this->assertTrue($this->file($fids[0])->isPermanent(), 'A file a media entity holds is permanent.');

    // A stored association resolves in the upload request itself.
    $editor->set(EditorSettings::FIELD_NAME, ['file_associations' => ['png' => 'image']]);
    $editor->save();
    $response = $this->send('POST', $new, $token, $png);
    $this->assertSame(200, $response['status'], $response['body']);
    $resolution = Json::decode($response['body'])['resolution'] ?? NULL;
    $this->assertSame('image', $resolution['block_type'] ?? NULL, $response['body']);
    $this->assertCount(2, $this->mediaIds());
    $this->assertContains($resolution['media_target_id'], $this->mediaIds());
    $fids = $this->fileIds();
    $this->assertCount(2, $fids);
    $this->assertTrue($this->file($fids[1])->isPermanent());

    // The drop on a media reference: media of the named bundle, held file.
    $response = $this->send('POST', $replace . 'image', $token, $png);
    $this->assertSame(200, $response['status'], $response['body']);
    $this->assertCount(3, $this->mediaIds());
    $fids = $this->fileIds();
    $this->assertCount(3, $fids);
    $this->assertTrue($this->file($fids[2])->isPermanent());

    // Its lane is the bundle's own: a kind another media type takes is
    // refused for this one.
    $svg = ['mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'];
    $response = $this->send('POST', $replace . 'image', $token, $svg);
    $this->assertSame(400, $response['status'], $response['body']);
    // A bundle that does not exist, and one whose source is not a file.
    $this->assertSame(404, $this->send('POST', $replace . 'nothing', $token, $png)['status']);
    $this->createMediaType('oembed:video', ['id' => 'remote_video']);
    $role = Role::load('daedalus_editor');
    $role->grantPermission('create remote_video media')->save();
    $response = $this->send('POST', $replace . 'remote_video', $token, $png);
    $this->assertSame(400, $response['status'], $response['body']);
    $this->assertStringContainsString('does not keep its source in a file', $response['body']);
    $this->assertCount(3, $this->fileIds(), 'A refused bundle saved no file.');
    $this->assertCount(3, $this->mediaIds());

    // Media is created only by right. Without create access on the bundle
    // the reference drop is refused before a file is saved, and the page
    // drop's resolution is refused with its file left temporary.
    $role->revokePermission('create image media')->save();
    $response = $this->send('POST', $replace . 'image', $token, $png);
    $this->assertSame(403, $response['status'], $response['body']);
    $this->assertCount(3, $this->fileIds(), 'A refused creation saved no file.');
    $response = $this->send('POST', $new, $token, $png);
    $this->assertSame(403, $response['status'], $response['body']);
    $this->assertCount(3, $this->mediaIds(), 'No media was created without create access.');
    $fids = $this->fileIds();
    $this->assertCount(4, $fids);
    $this->assertTrue($this->file($fids[3])->isTemporary(), 'The file no media took stays temporary.');
    $role->grantPermission('create image media')->save();

    // Update on the host is not enough: the routes are Edit Mode's.
    $role->revokePermission('use daedalus ui edit mode')->save();
    $this->assertSame(403, $this->send('POST', $new, $token, $png)['status']);
    $this->assertSame(403, $this->send('POST', $replace . 'image', $token, $png)['status']);
    $this->assertSame(403, $this->submit($submit_url, $fields)['status']);
    $this->assertCount(4, $this->fileIds());
    $this->assertCount(3, $this->mediaIds());
  }

  /**
   * Sends a request to an upload route as the browser session.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The route's path.
   * @param string|null $token
   *   The X-CSRF-Token header's value, or NULL to send none.
   * @param array{string, string}|null $file
   *   The dropped file as [name, bytes], sent as the multipart `file` part
   *   the upload script sends, or NULL for no body.
   *
   * @return array{status: int, body: string}
   *   The response status and body.
   */
  private function send(string $method, string $path, ?string $token, ?array $file = NULL): array {
    $options = [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
      'query' => self::QUERY,
    ];
    if ($token !== NULL) {
      $options['headers']['X-CSRF-Token'] = $token;
    }
    if ($file !== NULL) {
      $options['multipart'] = [['name' => 'file', 'filename' => $file[0], 'contents' => $file[1]]];
    }
    $response = $this->getHttpClient()->request($method, $this->buildUrl($path), $options);
    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
  }

  /**
   * Submits the chooser form the way core's AJAX form submit does.
   *
   * @param string $url
   *   The submit URL the form's AJAX settings name.
   * @param array<string, string> $fields
   *   The form's fields.
   *
   * @return array{status: int, body: string}
   *   The response status and body.
   */
  private function submit(string $url, array $fields): array {
    // The wrapper format is what core's ajax.js adds to every request.
    $response = $this->getHttpClient()->request('POST', $this->getAbsoluteUrl($url) . '&_wrapper_format=drupal_ajax', [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
      'form_params' => $fields,
    ]);
    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
  }

  /**
   * The resolution a chooser submission handed the client, or NULL.
   */
  private function chosen(string $body): ?array {
    foreach (Json::decode($body) ?? [] as $command) {
      if (is_array($command) && ($command['method'] ?? '') === 'daedalusUiFileAssociationChosen') {
        return $command['args'][0];
      }
    }
    return NULL;
  }

  /**
   * A hidden input's value in a form's markup.
   */
  private function hiddenValue(string $markup, string $name): string {
    $document = new \DOMDocument();
    @$document->loadHTML($markup);
    $input = (new \DOMXPath($document))->query(sprintf('//input[@name="%s"]', $name))->item(0);
    $this->assertInstanceOf(\DOMElement::class, $input, "The form carries $name.");
    return $input->getAttribute('value');
  }

  /**
   * The session's CSRF token, as the page builder's JS fetches it.
   */
  private function csrfToken(): string {
    $this->drupalGet('session/token');
    return $this->getSession()->getPage()->getContent();
  }

  /**
   * The id of every managed file this test's requests saved, oldest first.
   *
   * @return list<int>
   *   The file ids.
   */
  private function fileIds(): array {
    return array_values(array_diff($this->ids('file', 'fid'), $this->shipped['file']));
  }

  /**
   * The id of every media entity this test's requests created, oldest first.
   *
   * @return list<int>
   *   The media ids.
   */
  private function mediaIds(): array {
    return array_values(array_diff($this->ids('media', 'mid'), $this->shipped['media']));
  }

  /**
   * Every id of an entity type, oldest first.
   *
   * @return list<int>
   *   The ids.
   */
  private function ids(string $entity_type, string $key): array {
    $ids = $this->container->get('entity_type.manager')->getStorage($entity_type)->getQuery()->accessCheck(FALSE)->sort($key)->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * A file, read fresh from storage.
   */
  private function file(int $fid): FileInterface {
    $this->container->get('entity_type.manager')->getStorage('file')->resetCache([$fid]);
    $file = File::load($fid);
    $this->assertInstanceOf(FileInterface::class, $file);
    return $file;
  }

}
