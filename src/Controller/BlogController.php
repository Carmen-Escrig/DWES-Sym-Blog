<?php

namespace App\Controller;

use Symfony\Component\Filesystem\Filesystem;
use App\Entity\Comment;
use App\Entity\Post;
use App\Entity\Category;
use App\Form\CommentFormType;
use App\Form\PostFormType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\HttpFoundation\Session\SessionInterface;



class BlogController extends AbstractController
{
    use TargetPathTrait;
    #[Route("/blog/buscar/{page}", name: 'blog_buscar')]
    public function buscar(ManagerRegistry $doctrine,  Request $request, int $page = 1): Response
    {
        $repositoryPost = $doctrine->getRepository(Post::class);
        $repositoryCat = $doctrine->getRepository(Category::class);

        $search = $request->query->get('searchTerm') ?? '';

        $posts = $repositoryPost->findByText($search);

        $categories = $repositoryCat->findAll();
        $recents = $repositoryPost->findRecents();
        
        return $this->render('blog/blog.html.twig', [
            'posts' => $posts,
            'categories' => $categories,
            'recents' => $recents,
        ]);
    } 
   
    #[Route("/blog/new", name: 'new_post')]
    public function newPost(ManagerRegistry $doctrine, Request $request, SluggerInterface $slugger): Response
    {
        $post = new Post();
        
        $form = $this->createForm(PostFormType::class, $post);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $file = $form->get('Image')->getData();
            if ($file) {
                $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                // this is needed to safely include the file name as part of the URL
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

                // Move the file to the directory where images are stored
                try {

                    $file->move(
                        $this->getParameter('post_image_directory'), $newFilename
                    );
                    /*$filesystem = new Filesystem();
                     $filesystem->copy(
                        $this->getParameter('images_directory') . '/'. $newFilename, 
                        $this->getParameter('portfolio_directory') . '/'.  $newFilename, true); */

                } catch (FileException $e) {
                    return new Response("Error: " . $e->getMessage());
                }

                // updates the 'file$filename' property to store the PDF file name
                // instead of its contents
                $post->setImage($newFilename);
            }
            
            $post = $form->getData();

            $post->setUser($this->getUser());

            $post->setNumLikes(0);
            $post->setNumComments(0);
            $post->setNumViews(0);

            $post->setSlug($slugger->slug($post->getTitle()));
            $entityManager = $doctrine->getManager();
            $entityManager->persist($post);

            try {
                $entityManager->flush();
                return $this->redirectToRoute('blog');
            } catch (\Exception $e) {
                return new Response("Error: " . $e->getMessage());
            }
        }

        return $this->render('blog/new_post.html.twig', [
            'controller_name' => 'PageController',
            'form' => $form->createView()
        ]);
    }
    
    #[Route("/single_post/{slug}/like", name: 'post_like')]
    public function like(ManagerRegistry $doctrine, Request $request, SessionInterface $session, $slug): Response
    {
        $repositoryPost = $doctrine->getRepository(Post::class);

        $post = $repositoryPost->findOneBy(["Slug" => $slug]);
        $post->addLike();

        $entityManager = $doctrine->getManager();
        $entityManager->persist($post);

        try {
            $entityManager->flush();
            return $this->redirectToRoute("single_post", [
                "slug" => $slug,
            ]);
        } catch (\Exception $e) {
            return new Response("Error: " . $e->getMessage());
        }
        
    }

    #[Route("/blog", name: 'blog')]
    public function index(ManagerRegistry $doctrine): Response
    {
        $repositoryPost = $doctrine->getRepository(Post::class);
        $repositoryCat = $doctrine->getRepository(Category::class);
        $posts = $repositoryPost->findAll();
        $categories = $repositoryCat->findAll();
        $recents = $repositoryPost->findRecents();
        
        return $this->render('blog/blog.html.twig', [
            'posts' => $posts,
            'categories' => $categories,
            'recents' => $recents,
        ]);
    }

    #[Route("/single_post/{slug}", name: 'single_post')]
    public function post(ManagerRegistry $doctrine, Request $request, $slug): Response
    {
        $repositoryPost = $doctrine->getRepository(Post::class);
        $post = $repositoryPost->findOneBy(["Slug" => $slug]);
        $recents = $repositoryPost->findRecents();

        $comment = new Comment();
        
        $form = $this->createForm(CommentFormType::class, $comment);

        $form->handleRequest($request);

        if($form->isSubmitted() && $form->isValid()) {
            $comment = $form->getData();
            $comment->setPost($post);

            $post->setNumComments($post->getNumComments() + 1);

            $entityManager = $doctrine->getManager();
            $entityManager->persist($comment);
            $entityManager->persist($post);

            try {
                $entityManager->flush();
                return $this->redirectToRoute("single_post", [
                    "slug" => $slug,
                ]);
            } catch (\Exception $e) {
                return new Response("Error" . $e->getMessage());
            }
        }

        return $this->render('blog/single_post.html.twig', [
            'post' => $post,
            'recents' => $recents,
            'commentForm' => $form->createView(),
        ]);
    }
}
