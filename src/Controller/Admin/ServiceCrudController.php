<?php

namespace App\Controller\Admin;

use App\Entity\Service;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;

class ServiceCrudController extends AbstractCrudController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}
    public static function getEntityFqcn(): string
    {
        return Service::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Service')
            ->setEntityLabelInPlural('Services')
            ->setSearchFields(['title', 'description', 'byProfile.user.email'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $publishAction = Action::new('publish', 'Publier', 'fa fa-check')
            ->linkToUrl(function (Service $service): string {
                return $this->generateUrl('admin_service_publish', ['id' => $service->getId()]);
            })
            ->setCssClass('btn btn-success')
            ->displayIf(fn (Service $service) => $service->getStatus() !== Service::STATUS_PUBLISHED);

        $rejectAction = Action::new('reject', 'Rejeter', 'fa fa-times')
            ->linkToUrl(function (Service $service): string {
                return $this->generateUrl('admin_service_reject', ['id' => $service->getId()]);
            })
            ->setCssClass('btn btn-danger')
            ->displayIf(fn (Service $service) => $service->getStatus() !== Service::STATUS_ARCHIVED);

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $publishAction)
            ->add(Crud::PAGE_INDEX, $rejectAction)
            ->disable(Action::NEW, Crud::PAGE_NEW)
            ->disable(Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnDetail(),
            TextField::new('title', 'Titre'),
            MoneyField::new('cost', 'Prix')
                ->setCurrency('EUR')
                ->setStoredAsCents(false),
            TextEditorField::new('description', 'Description')
                ->hideOnIndex(),
            AssociationField::new('byProfile', 'Auteur')
                ->formatValue(function ($value, $entity) {
                    return $entity->getByProfile()->getOfUser()->getEmail();
                }),
            ChoiceField::new('status', 'Statut')
                ->setChoices([
                    'Brouillon' => Service::STATUS_DRAFT,
                    'En attente' => Service::STATUS_PENDING_REVIEW,
                    'Publié' => Service::STATUS_PUBLISHED,
                    'Archivé' => Service::STATUS_ARCHIVED,
                ])
                ->renderAsBadges([
                    Service::STATUS_PUBLISHED => 'success',
                    Service::STATUS_PENDING_REVIEW => 'warning',
                    Service::STATUS_DRAFT => 'secondary',
                    Service::STATUS_ARCHIVED => 'danger',
                ]),
            DateTimeField::new('createdAt', 'Créé le')
                ->setFormat('dd/MM/yyyy HH:mm')
                ->hideOnForm(),
            DateTimeField::new('updatedAt', 'Modifié le')
                ->setFormat('dd/MM/yyyy HH:mm')
                ->hideOnForm(),
            BooleanField::new('isAvailable', 'Disponible'),
            BooleanField::new('isRemote', 'À distance')
                ->renderAsSwitch(false),
        ];
    }

    #[Route('/admin/service/{id}/publish', name: 'admin_service_publish', methods: ['GET'])]
    public function publishService(Service $service, AdminUrlGenerator $adminUrlGenerator): Response
    {
        try {
            $service->setStatus(Service::STATUS_PUBLISHED);
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Le service a été publié avec succès.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Une erreur est survenue : ' . $e->getMessage());
        }
        
        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
            
        return $this->redirect($url);
    }
    
    #[Route('/admin/service/{id}/reject', name: 'admin_service_reject', methods: ['GET'])]
    public function rejectService(Service $service, AdminUrlGenerator $adminUrlGenerator): Response
    {
        try {
            $service->setStatus(Service::STATUS_ARCHIVED);
            $this->entityManager->flush();
            
            $this->addFlash('warning', 'Le service a été rejeté et archivé.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Une erreur est survenue : ' . $e->getMessage());
        }
        
        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
            
        return $this->redirect($url);
    }
}
