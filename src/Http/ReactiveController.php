<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Http;

use FloCMS\Core\{Controller, HttpException};
use FloCMS\Core\Http\Response;
use FloCMS\Reactive\Reactive;

final class ReactiveController extends Controller
{
    public function update(): Response
    {
        try { return Reactive::kernel()->update($this->request); }
        catch (HttpException $e) {
            return Response::json(['message' => $e->getMessage()], $e->status)->header('Cache-Control', 'no-store');
        }
    }

    /** Serve only known build files. No arbitrary filesystem paths. */
    public function asset(): Response
    {
        if (!$this->request->isMethod('GET', 'HEAD')) { return Response::text('Method not allowed.', 405)->header('Allow', 'GET, HEAD'); }
        $name = $this->params[0] ?? '';
        if (count($this->params) !== 1 || !is_string($name) || preg_match('/^reactive-[A-Za-z0-9_-]+\.(js|css)$/D', $name, $match) !== 1) { throw new HttpException(404); }
        $file = dirname(__DIR__, 2) . '/dist/' . $name;
        if (!is_file($file)) { throw new HttpException(404); }
        return Response::make((string) file_get_contents($file))
            ->header('Content-Type', $match[1] === 'js' ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=31536000, immutable')->header('X-Content-Type-Options', 'nosniff');
    }
}
