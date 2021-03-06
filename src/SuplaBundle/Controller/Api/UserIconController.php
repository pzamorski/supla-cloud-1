<?php
/*
 Copyright (C) AC SOFTWARE SP. Z O.O.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.
 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace SuplaBundle\Controller\Api;

use Assert\Assertion;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use FOS\RestBundle\Controller\Annotations as Rest;
use Gumlet\ImageResize;
use Gumlet\ImageResizeException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use SuplaBundle\Entity\EntityUtils;
use SuplaBundle\Entity\UserIcon;
use SuplaBundle\Enums\ChannelFunction;
use SuplaBundle\EventListener\UnavailableInMaintenance;
use SuplaBundle\Exception\ApiException;
use SuplaBundle\Model\Transactional;
use SuplaBundle\Repository\UserIconRepository;
use SuplaBundle\Supla\SuplaServerAware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class UserIconController extends RestController {
    use SuplaServerAware;
    use Transactional;

    /** @var UserIconRepository */
    private $userIconRepository;

    public function __construct(UserIconRepository $userIconRepository) {
        $this->userIconRepository = $userIconRepository;
    }

    protected function getDefaultAllowedSerializationGroups(Request $request): array {
        return ['images'];
    }

    /**
     * @Security("has_role('ROLE_CHANNELS_RW')")
     * @Rest\Post("/user-icons")
     * @UnavailableInMaintenance
     */
    public function postIconAction(Request $request) {
        $files = $request->files;
        Assertion::greaterThan(count($files), 0, 'You have not uploaded any files, or the uploaded files are too big.');
        /** @var ChannelFunction $function */
        $function = ChannelFunction::fromString($request->get('function', ''));
        $sourceIcon = $request->get('sourceIcon');
        $icon = new UserIcon($this->getUser(), $function);
        if ($sourceIcon) {
            $sourceIcon = $this->userIconRepository->findForUser($this->getUser(), $sourceIcon);
            Assertion::eq($function->getId(), $sourceIcon->getFunction()->getId(), 'Function of the edited icons mismatch.');
        }
        $imagesCount = count($function->getPossibleVisualStates());
        for ($iconIndex = 1; $iconIndex <= $imagesCount; $iconIndex++) {
            $imageFileNameInRequest = 'image' . $iconIndex;
            if (!$sourceIcon) {
                Assertion::true($files->has($imageFileNameInRequest), "Icon for this function must consist of $imagesCount images.");
            }
            if ($files->has($imageFileNameInRequest)) {
                try {
                    $image = new ImageResize($files->get($imageFileNameInRequest)->getPathName());
                    $image = $image->resizeToHeight(156, true)->getImageAsString(IMAGETYPE_PNG);
                    $image = ImageResize::createFromString($image);
                    $imageString = $image->crop(210, 156)->getImageAsString(IMAGETYPE_PNG);
                } catch (ImageResizeException $exception) {
                    throw new ApiException($exception->getMessage(), 400, $exception);
                }
            } else {
                $imageString = $sourceIcon->getImages()[$iconIndex - 1];
            }
            $method = 'setImage' . $iconIndex;
            $icon->$method($imageString);
        }
        $this->transactional(function (EntityManagerInterface $em) use ($icon, $sourceIcon) {
            $em->persist($icon);
            if ($sourceIcon) {
                foreach ($sourceIcon->getChannels() as $channel) {
                    $channel->setUserIcon($icon);
                    $em->persist($channel);
                }
                foreach ($sourceIcon->getChannelGroups() as $channelGroup) {
                    $channelGroup->setUserIcon($icon);
                    $em->persist($channelGroup);
                }
                $em->remove($sourceIcon);
            }
        });
        return $this->view($icon);
    }

    /**
     * @Rest\Get("/user-icons")
     * @Security("has_role('ROLE_CHANNELS_R')")
     */
    public function getUserIconsAction(Request $request) {
        $criteria = Criteria::create();
        if (($function = $request->get('function')) !== null) {
            $functionIds = EntityUtils::mapToIds(ChannelFunction::fromStrings(explode(',', $function)));
            $criteria->andWhere(Criteria::expr()->in('function', $functionIds));
        }
        if (($ids = $request->get('ids')) !== null) {
            $criteria->andWhere(Criteria::expr()->in('id', explode(',', $ids)));
        }
        $channels = $this->getUser()->getUserIcons()->matching($criteria);
        return $this->serializedView($channels, $request);
    }

    /**
     * @Rest\Get("/user-icons/{userIcon}/{imageIndex}")
     * @Security("userIcon.belongsToUser(user) and has_role('ROLE_CHANNELS_FILES')")
     * @Cache(maxage="86400", smaxage=86400)
     */
    public function getUserIconImageAction(UserIcon $userIcon, int $imageIndex) {
        $image = $userIcon->getImages()[$imageIndex];
        return new Response($image);
    }

    /**
     * @Rest\Delete("/user-icons/{userIcon}")
     * @Security("userIcon.belongsToUser(user) and has_role('ROLE_CHANNELS_RW')")
     * @UnavailableInMaintenance
     */
    public function deleteUserIconAction(UserIcon $userIcon) {
        return $this->transactional(function (EntityManagerInterface $em) use ($userIcon) {
            $em->remove($userIcon);
            return new Response('', Response::HTTP_NO_CONTENT);
        });
    }
}
