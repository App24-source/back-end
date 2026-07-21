<?php

namespace App\Http\Controllers\Home;

use Illuminate\Http\Request;
use App\Http\Controllers\Home\marketplaceFunctions\TopSliderController;
use App\Http\Controllers\Home\marketplaceFunctions\MarketplacePublicationsController;
use App\Http\Controllers\Controller;

class MarketplaceController extends Controller
{
    /**
     * Get marketplace tags slider - routes to TopSliderController
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTagsSlider( Request $request )
    {
        $topSliderController = app( TopSliderController::class);
        return $topSliderController->getMarketplaceTagsSlider( $request );
    }

    /**
     * Get marketplace tags slider new (for mngr_is_index = 0) - routes to TopSliderController
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTagsSliderNew( Request $request )
    {
        $topSliderController = app( TopSliderController::class);
        return $topSliderController->getMarketplaceTagsSliderNew( $request );
    }

    /**
     * Get marketplace publications - routes to MarketplacePublicationsController
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPublications( Request $request )
    {
        $publicationsController = app( MarketplacePublicationsController::class);
        return $publicationsController->getMarketplacePublications( $request );
    }
}

