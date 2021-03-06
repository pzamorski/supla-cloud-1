<?php
namespace SuplaBundle\Model\ChannelParamsUpdater;

use SuplaBundle\Enums\ChannelFunction;

class OpeningGatewaySensorRelatedChannel extends OpeningAnySensorRelatedChannel {
    public function __construct() {
        parent::__construct(ChannelFunction::CONTROLLINGTHEGATEWAYLOCK(), ChannelFunction::OPENINGSENSOR_GATEWAY());
    }
}
