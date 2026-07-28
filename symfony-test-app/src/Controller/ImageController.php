<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class ImageController extends AbstractController
{
    public function __construct(
        #[Autowire(param: 'product_images_dir')]
        private readonly string $imageStorageDir,
    ) {}

    #[Route('/images/{filename}', name: 'product_image', requirements: ['filename' => '[^/]+\.[a-zA-Z]{2,5}'])]
    public function serve(string $filename): BinaryFileResponse
    {
        // basename() prevents any path traversal attempt.
        $safe = basename($filename);
        $path = $this->imageStorageDir . '/' . $safe;

        if (!is_file($path)) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($path);
        $response->setMaxAge(86400);
        $response->setPublic();

        return $response;
    }
}
