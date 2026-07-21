<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AnnonceController;
use App\Http\Controllers\FilterController; 
use App\Http\Controllers\StatisticsEvents\EventStatisticController;
use App\Http\Controllers\settings\UserController;
use App\Http\Controllers\annonce\CreateController;
use App\Http\Controllers\annonce\AddUpdateVideoController;
use App\Http\Controllers\boutique\DetailsBoutiqueController;
use App\Http\Controllers\annonce\PostulerEmploiController;
use App\Http\Controllers\publication\UserPublicationsController;
use App\Http\Controllers\builder\BuilderController;
use App\Http\Controllers\produit\ProduitController;
use App\Http\Controllers\booster\BoosterController;
use App\Http\Controllers\facturation\FacturationController;
use App\Http\Controllers\facturation\AppMarketController;
use App\Http\Controllers\notifications\NotificationController;
use App\Http\Controllers\publication\PublicationController;
use App\Http\Controllers\locations\LocationController;
use App\Http\Controllers\tags\TagsController;
use App\Http\Controllers\publication\create\CreatePublicationController;
use App\Http\Controllers\laivraison\LaivraisonController;
use App\Http\Controllers\statistiques\StatistiquesController;
use App\Http\Controllers\publication\sauvgarder\SauvgardeController;
use App\Http\Controllers\publication\share\SharePublicationController;
use App\Http\Controllers\users\UsersController;
use App\Http\Controllers\settings\SettingController;
use App\Http\Controllers\etablissement\EtablissementController;
use App\Http\Controllers\sitemap\SitemapController;
use App\Http\Controllers\Message\MessageController;
use App\Http\Controllers\follows\FollowsController;
use App\Http\Controllers\profile\ProfileController;
use App\Http\Controllers\ads\AdsController;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\TestController; 
use App\Http\Controllers\search\SearchController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Services\NotificationService;
use App\Http\Controllers\BounceEmailController;
use App\Http\Controllers\Home\HomeController;
use App\Http\Controllers\statistiques\InterestController;
use App\Http\Controllers\Home\customFlux\CustomFluxController;
use App\Http\Controllers\Home\marketplaceFunctions\TopSliderController;
use App\Http\Controllers\Home\marketplaceFunctions\MarketplacePublicationsController;
use App\Http\Controllers\Home\MarketplaceController;
use App\Http\Controllers\Home\catalogue\CatalogueController;

/* |-------------------------------------------------------------------------- | API Routes |-------------------------------------------------------------------------- | | Here is where you can register API routes for your application. These | routes are loaded by the RouteServiceProvider within a group which | is assigned the "api" middleware group. Enjoy building your API! | */

// In routes/api.php or any route file
// Route::options('{any}', function () {
//     return response()->json('OK');
// })->where('any', '.*');


Route::middleware( ['auth:sanctum'] )->get( '/user', function ( Request $request ) {
    return $request->user()->load( ['get_cv_users', 'get_ville', 'get_region', 'coupons', 'etablissements', 'etablissements.ville:id,slug,name', 'etablissements.region:id,slug,name', 'etablissements.coupons', 'emplois', 'publications_sauvgarde:id', 'etablissements.publications_sauvgarde:id', 'telephones', 'etablissements.telephones'] );
} );

// publication flink api
Route::get( '/get_publications', [PublicationController::class, 'index'] );



Route::get( '/get_keywords', [PublicationController::class, 'keywordsTags'] );
Route::middleware('throttle:120,1')->group(function () {
    Route::post( '/get_marketplace_publications', [PublicationController::class, 'marketplacePublications'] );
    Route::get( '/get_marketplace_publications_paginations_infos', [PublicationController::class, 'marketplacePublicationsPaginationInfos'] );
    Route::get( '/get_marketplace_publications', [MarketplacePublicationsController::class, 'getMarketplacePublications'] );
    Route::post( '/marketplace', [MarketplaceController::class, 'getPublications'] );
});

Route::get( '/get_marketplace_tags_slider', [TopSliderController::class, 'getMarketplaceTagsSlider'] );
Route::get( '/publications/slug', [PublicationController::class, 'publicationDetails'] );
Route::get( '/publications/popular_tags', [PublicationController::class, 'getPopularTags'] );
Route::get( '/publications/following_publications', [PublicationController::class, 'followingPublications'] );
Route::post( '/interests/add-from-publication', [HomeController::class, 'addInterestFromPublication'] );
// test
// Route::post('/get_marketplace_test', [TestController::class, 'marketplacePublications']);

// locations flink api
Route::get( '/flink/pays', [LocationController::class, 'pays'] );
Route::get( '/flink/villes/{pays}', [LocationController::class, 'villes'] );
Route::get( '/flink/regions/{villes}', [LocationController::class, 'regions'] );
Route::post( '/flink/locations/verify', [LocationController::class, 'VerifyLocations'] );

// tags flink api
Route::get( '/flink/tags', [TagsController::class, 'tags'] );

// laivraison flink api
Route::get( '/flink/modes_laivraison', [LaivraisonController::class, 'index'] );

// statiqtiques flink api
Route::post( '/flink/statiqtiques/store', [StatistiquesController::class, 'storeStatistiques'] );
Route::post( '/flink/statiqtiques/store_views', [StatistiquesController::class, 'storeViewsStatistique'] );

// User flink api
Route::get( '/flink/user/{slug}/{profileSlug?}', [UsersController::class, 'getUserEtablissement'] );

// sitemap flink api
Route::get( '/flink/get_sitemaps_tags', [SitemapController::class, 'generateSitemapUrlsTags'] );
Route::get( '/flink/get_sitemaps_pays', [SitemapController::class, 'generateSitemapUrlsPays'] );
Route::get( '/flink/get_sitemaps_villes', [SitemapController::class, 'generateSitemapUrlsVilles'] );
Route::get( '/flink/get_sitemaps_regions', [SitemapController::class, 'generateSitemapUrlsRegions'] );
Route::get( '/flink/get_sitemaps_images', [SitemapController::class, 'generateSitemapUrlsImages'] );
Route::get( '/flink/get_sitemaps_videos', [SitemapController::class, 'generateSitemapUrlsVideos'] );
Route::get( '/flink/get_sitemaps_posts', [SitemapController::class, 'generateSitemapUrlsPosts'] );
Route::get( '/flink/get_sitemaps_tags_rss', [SitemapController::class, 'generateSitemapUrlsTagsRSS'] );
Route::get( '/flink/get_sitemaps_users', [SitemapController::class, 'generateSitemapUrlsUsers'] );
Route::get( '/flink/get_sitemaps_etablissements', [SitemapController::class, 'generateSitemapUrlsEtablissements'] );



Route::get( '/booster/verify_number/{number}', [BoosterController::class, 'checkPhoneNumber'] );
Route::get( '/booster/get_boosted_by_slug', [BoosterController::class, 'getBoostedBySlug'] );

// Search Controller flink api
Route::middleware('throttle:120,1')->group(function () {
    Route::get( '/flink/search/top_publications', [SearchController::class, 'TopPublications'] );
    Route::get( '/flink/search/profiles', [SearchController::class, 'Profiles'] );
    Route::get( '/flink/search/marketplace', [SearchController::class, 'MarketplaceSearch'] );
    Route::get( '/flink/search/videos', [SearchController::class, 'PublicationsVideos'] );
    Route::get( '/flink/search/tags_search', [SearchController::class, 'TagsSearch'] );
    Route::get( '/flink/search/historiques_search', [SearchController::class, 'TagsSearchHistoriques'] );
});



// manager declanche notifications
Route::post( '/notifications/declanche_manager', [NotificationController::class, 'DeclancheNotifications'] );
Route::post( '/notifications/store_system_notification', [NotificationController::class, 'storeSystemNotification'] );


// test flink api
Route::get( '/flink/test_score', [PublicationController::class, 'calculateAnnonceScores'] );

Route::post( '/process-square-images', [CreatePublicationController::class, 'processAllSquareImages'] );

Route::get( '/getContacts', [SharePublicationController::class, 'getContactsBySlug'] );
Route::get( '/publications/download-video', [SharePublicationController::class, 'downloadHlsAsMp4'] );

// Video processing routes
Route::post( '/publications/start-video-processing', [SharePublicationController::class, 'startVideoProcessing'] );
Route::get( '/publications/video-processing-status', [SharePublicationController::class, 'getVideoProcessingStatus'] );
Route::get( '/publications/download-processed-video/{job_id}', [SharePublicationController::class, 'downloadProcessedVideo'] )->name( 'download.processed.video' );

// Image processing routes
Route::post( '/publications/start-image-processing', [SharePublicationController::class, 'startImageProcessing'] );
Route::get( '/publications/image-processing-status', [SharePublicationController::class, 'getImageProcessingStatus'] );
Route::get( '/publications/download-processed-image/{job_id}/{image_index}', [SharePublicationController::class, 'downloadProcessedImage'] )->name( 'download.processed.image' );

// Repost publication
Route::post( '/publications/repost', [SharePublicationController::class, 'repostPublication'] );


Route::post( '/report-publication', [SharePublicationController::class, 'reportPublication'] );
Route::post( '/publications/unrepost', [SharePublicationController::class, 'unrepostPublication'] );


Route::middleware( ['auth:sanctum'] )->group( function () {
    // get vehicule informations
    // Route::get('/get_marques', [AnnonceController::class, 'getMarques']);
    // Route::get('/get_models/{id}', [AnnonceController::class, 'getModels']);
    // Route::get('/get_annees', [AnnonceController::class, 'getAnnees']);
    // Route::get('/get_immatriculations', [AnnonceController::class, 'getImmatriculations']);
    // Route::get('/get_carburants', [AnnonceController::class, 'getCarburants']);
    // Route::get('/get_fiscales', [AnnonceController::class, 'getFiscales']);
    // Route::get('/get_caractiristiques', [AnnonceController::class, 'getCaractiristiques']);

    // get emplois informations
    // Route::get('/get_emplois_activites', [AnnonceController::class, 'getEmploiActivites']);
    // Route::get('/get_emplois_contrats', [AnnonceController::class, 'getEmploiContrats']);
    // Route::get('/get_emplois_etudes', [AnnonceController::class, 'getEmploiEtudes']);
    // Route::get('/get_emplois_experiences', [AnnonceController::class, 'getEmploiExperiences']);
    // Route::get('/get_emplois_fonctions', [AnnonceController::class, 'getEmploiFonctions']);
    // Route::get('/get_emplois_salaires', [AnnonceController::class, 'getEmploiSalaires']);
    // Route::get('/get_emplois_travails', [AnnonceController::class, 'getEmploiTravails']);
    // Route::get('/get_domaine_formations', [AnnonceController::class, 'getDomainFormation']);


    // settings / UserController Routes
    // Route::post('/users/{id}', [UserController::class, 'insert']);

    // annonce / CreateController Routes
    // Route::post('/annonces/createAnnonce', [CreateController::class, 'store']);
    // Route::post('/annonces/updateAnnonce/{id}', [CreateController::class, 'update']);
    Route::post( 'publications/unlockPhoneNumber', [CreatePublicationController::class, 'UnlockPhoneNumber'] );

    // annonce / AddUpdateVideoController Routes
    // Route::post('/annonces/add_update_video', [AddUpdateVideoController::class, 'store']); 
    Route::get( '/annonces/get_publications_user/{id}', [AddUpdateVideoController::class, 'getPublicationsByUser'] );

    // boutique / DetailsBoutiqueController Routes
    Route::post( '/boutique/create_boutique', [DetailsBoutiqueController::class, 'store'] );
    // Route::get('/boutique/all_boutiques', [DetailsBoutiqueController::class, 'getBoutiques']);
    // Route::get('/boutique/get_slug_boutiques', [DetailsBoutiqueController::class, 'getSlugBoutiques']);

    // Route::get('/annonce/impressions', [AnnonceController::class, 'getImpressions']);


    // annonce / PostulerEmploiController Routes
    // Route::post('/annonce/postuler_emploi', [PostulerEmploiController::class, 'store']);

    // annonce / UserPublicationsController  Routes
    Route::get( '/publications/boosted_publications', [UserPublicationsController::class, 'getBoostedPublications'] );
    Route::get( '/publications/profile_publications/{slug}', [UserPublicationsController::class, 'index'] );

    // booster / BoosterController Routes
    // Route::get( '/booster/get_boosted_by_slug', [BoosterController::class, 'getBoostedBySlug'] ); // Moved outside auth middleware
    // Route::post('/publications/delete_publications/{annonceId}', [UserPublicationsController::class, 'delete']);

    // builder / BuilderController Routes
    // Route::get('/builder/project_websites/{projectId}', [BuilderController::class, 'ProjectWebsites']);
    // Route::get('/builder/openSession', [BuilderController::class, 'openSession']);
    // Route::get('/builder/create_account/{userEtabId}/{type}', [BuilderController::class, 'createAccount']);
    // Route::get('/builder/add_website', [BuilderController::class, 'AddWebsite']);

    // produit / ProduitController Routes
    // Route::get('/produit/panier/{userId}', [ProduitController::class, 'PanierUser']);

    // booster / BoosterController Routes
    Route::post( '/booster/create_booste', [BoosterController::class, 'store'] );


    // facturation / FacturationController Routes
    Route::get( '/facturations/recapitulatif/{userEtabId}/{type}', [FacturationController::class, 'getRecapitulatifFacturation'] );
    Route::get( '/facturations/subscriptions/{userEtabId}/{type}', [FacturationController::class, 'getSubscriptionsFacturation'] );

    // coupons / FacturationController Routes
    Route::get( '/facturations/coupons/{userEtabId}/{type}', [FacturationController::class, 'getCoupons'] );
    Route::post( '/facturations/coupons/coupon_apply', [FacturationController::class, 'couponApply'] );

    // points / FacturationController Routes
    Route::get( '/facturations/points_transactions/{userEtabId}/{type}', [FacturationController::class, 'getPointsTransactions'] );
    Route::post( '/facturations/points_transactions/send_points', [FacturationController::class, 'sendPoints'] );
    Route::post( '/facturations/add_new_facture', [AppMarketController::class, 'addNewFacture'] );

    // App Market / AppMarketController Routes
    Route::get( '/facturations/app_market_products', [AppMarketController::class, 'getAppMarketProducts'] );

    // Notifications / NotificationController Routes
    // Route::get('/notifications/{user_etab_id}/{type}', [NotificationController::class, 'getNotifications']);
    // Route::post('/notifications/create', [NotificationController::class, 'createNotifications']);

    Route::get( '/notifications/get_notifications', [NotificationController::class, 'getAllNotifications'] );
    Route::get( '/notifications/get_notifications_publications', [NotificationController::class, 'getPublicationNotifications'] );
    Route::get( '/notifications/get_notifications_followers', [NotificationController::class, 'getFollowerNotifications'] );
    Route::get( '/notifications/get_notifications_systems', [NotificationController::class, 'getSystemNotifications'] );
    Route::post( '/notifications/mark_as_read', [NotificationController::class, 'markAsRead'] );
    Route::post( '/notifications/mark_as_read_all', [NotificationController::class, 'markAsReadAll'] );
    Route::get( '/notifications/number_of_unread_notifications', [NotificationController::class, 'numberOfUnreadNotifications'] );


    // create flink api
    Route::post( '/publications/create', [CreatePublicationController::class, 'store'] );
    Route::post( '/publications/edit/{publicationId}', [CreatePublicationController::class, 'edit'] );
    Route::get( '/publications/get_publication/{id}', [CreatePublicationController::class, 'GetPublication'] );

    // Sauvgarde flink api
    Route::post( '/publications/sauvgarde', [SauvgardeController::class, 'Sauvgarde'] );

    // settings flink api
    Route::post( 'settings/update-user-info', [SettingController::class, 'updateUserInfo'] );
    Route::post( 'settings/update-password', [SettingController::class, 'updatePassword'] );
    Route::post( 'settings/update-user-personal-info', [SettingController::class, 'updateUserPersonalInfo'] );
    Route::post( 'settings/update-user-cvs', [SettingController::class, 'updateUserCVs'] );
    Route::delete( 'settings/delete-user-cv/{userId}/{cvId}', [SettingController::class, 'deleteUserCV'] );
    Route::post( 'settings/update-about-you', [SettingController::class, 'updateAboutYou'] );

    // etablissement flink api
    Route::get( 'etablissement/verify-slug/{type}', [EtablissementController::class, 'VerifySlug'] );

    // message flink api
    Route::post( '/send-message', [MessageController::class, 'sendMessage'] );
    Route::get( '/get-conversation', [MessageController::class, 'getConversationId'] );
    Route::post( '/get-users-in-conversations', [MessageController::class, 'getUsersInConversations'] );
    Route::post( '/mark-as-read', [MessageController::class, 'markAsRead'] );
    Route::post( '/mark-conversation-as-read', [MessageController::class, 'markConversationAsRead'] );
    Route::post( '/messages/like', [MessageController::class, 'likeMessage'] );
    Route::post( '/messages/delete', [MessageController::class, 'deleteMessage'] );
    Route::post( '/conversations/delete', [MessageController::class, 'deleteConversation'] );
    Route::post( '/conversations/pin', [MessageController::class, 'pinConversation'] );
    Route::get( '/messages/user_not_read_count/{profileSlug}', [MessageController::class, 'userMessageCount'] );

    Route::post( '/conversation/messages', [MessageController::class, 'getMessages'] );
    Route::post( '/get-last-message-seen', [MessageController::class, 'getLastMessageView'] );


    // Route pour marquer une conversation comme lue

    // follows flink api
    Route::post( 'profile/follows', [FollowsController::class, 'FollowEntity'] );
    Route::post( 'profile/follows/check', [FollowsController::class, 'checkFollowStatus'] );

    Route::post( 'profile/edite', [ProfileController::class, 'editeProfile'] );
    Route::post( 'profile/delete-publication', [ProfileController::class, 'deletePublication'] );

    // facturation
    Route::get( 'facturations/getCointOffers', [FacturationController::class, 'getCointOffers'] );
    Route::post( '/facturations/create', [FacturationController::class, 'createFacture'] );

    // statistiques
    Route::get( '/statistiques/analytics', [StatistiquesController::class, 'getAnalyticsData'] );
    Route::post( '/flink/statiqtiques/profile', [StatistiquesController::class, 'updateProfileStatistiques'] );
    Route::get( '/statistiques/analytics/publication', [StatistiquesController::class, 'getPublicationAnalytics'] );


    // searchController
    Route::delete( '/search/delete_tags_search_historiques', [SearchController::class, 'DeleteTagsSearch'] );

    // Interest tracking
    Route::post( '/interests/increment', [InterestController::class, 'incrementInterest'] );

    // Reaction system (full 5-type reactions)
    Route::post( '/reactions/store', [StatistiquesController::class, 'storeReaction'] );
    Route::get( '/reactions/get', [StatistiquesController::class, 'getReaction'] );

    // Catalogue bulk import
    Route::post( '/catalogue/import', [CatalogueController::class, 'import'] );
    Route::post( '/catalogue/preview', [CatalogueController::class, 'previewCatalogue'] );
    Route::post( '/catalogue/update', [CatalogueController::class, 'updateCatalogue'] );
    Route::post( '/catalogue/delete', [CatalogueController::class, 'deleteCatalogue'] );
    Route::post( '/catalogue/refresh', [CatalogueController::class, 'refreshCatalogue'] );
    Route::post( '/catalogue/status', [CatalogueController::class, 'getCatalogueProcessingStatus'] );
    Route::post( '/catalogue/cancel', [CatalogueController::class, 'cancelCatalogueProcessing'] );
    Route::post( '/catalogue/notice/ack', [CatalogueController::class, 'acknowledgeCatalogueCompletionNotice'] );
    Route::post( '/catalogue/logs/pending', [CatalogueController::class, 'getPendingCatalogueLogs'] );
    Route::post( '/catalogue/logs/history', [CatalogueController::class, 'getCatalogueLogsHistory'] );
    Route::post( '/catalogue/logs/ack', [CatalogueController::class, 'acknowledgeCatalogueLog'] );
    Route::post( '/catalogue/slots/purchase', [CatalogueController::class, 'purchaseSlots'] );
} );

// SSE Real-time progress streaming (outside auth:sanctum - uses token query param)
Route::get( '/catalogue/{catalogueId}/progress-stream', [CatalogueController::class, 'streamProgress'] );



Route::post( '/notifications/update_token', [NotificationController::class, 'updateToken'] );
// routes/web.php (or create a separate testing routes file)
Route::get( '/test/new-publication-notification', function () {
    $publication = \App\Models\Publication::find( 6954 );
    $user = \App\Models\User::find( 7991 );
    $user2 = \App\Models\User::find( 7430 );
    $etablissement = \App\Models\Etablissement::find( 30548 );
    // if (!$publication) return response()->json(['error' => 'Publication not found'], 404);

    // Eager load followers with email addresses
    // $followers = $publication->user->followersUsers;

    // if ($followers->isEmpty()) {
    //     return response()->json(['error' => 'No followers found'], 404);
    // }

    // Log before sending
    // \Log::info('Attempting to send notifications to:', $followers->pluck('email')->toArray());


    // return $publication->statistiques->sum('vues');

    // return $etablissement->notification_tokens;
    // $title = ['fr' => 'Nouvelle publication', 'en' => 'New publication', 'ar' => 'نشر جديدة'];
    // $message = ['fr' => 'Une nouvelle publication a été publiée', 'en' => 'A new publication has been published', 'ar' => 'تم نشر نشرة جديدة'];
    // app(NotificationService::class)->sendSystemNotification('both', $user, 'system', $title, $message, null, null, null, null);

    app( NotificationService::class)->sendNewFollowerNotification( $user, $user2 );

    // $followers = collect();

    // if ($publication->etab_id && $publication->etablissement) {
    //     // Get users following this establishment (excluding author)
    //     $followers = $publication->etablissement->followersUsers()
    //         ->where('users.id', '!=', $publication->user_id)
    //         ->get();

    //     // If you also want establishments following this establishment
    //     $establishmentFollowers = $publication->etablissement->followersEtablissements()
    //         ->with('users') // Assuming establishments have users
    //         ->get()
    //         ->pluck('users')
    //         ->flatten()
    //         ->unique('id')
    //         ->where('id', '!=', $publication->user_id);

    //     $followers = $followers->merge($establishmentFollowers);
    // } elseif ($publication->user) {
    //     // Get users following this user (excluding author)
    //     $followers = $publication->user->followersUsers()
    //         ->where('users.id', '!=', $publication->user_id)
    //         ->get();

    //     // If you want establishments following this user
    //     $establishmentFollowers = $publication->user->followersEtablissements()
    //         ->with('users')
    //         ->get()
    //         ->pluck('users')
    //         ->flatten()
    //         ->unique('id')
    //         ->where('id', '!=', $publication->user_id);

    //     $followers = $followers->merge($establishmentFollowers);
    // }

    // return $followers;
    // Notify all unique followers
    // $followers->unique('id')->each(function ($follower) use ($publication) {
    //     $follower->notify(new NewPublicationNotificationFollowers($publication));
    // });


    return response()->json( [
        // 'message' => 'Test notifications sent to ' . $followers->count() . ' followers',
        // 'publication_id' => $publication->id,
        // 'recipients' => $followers->pluck('email'),
        'queue_driver' => config( 'queue.default' ),
        'mail_driver' => config( 'mail.default' )
    ] );
} );
// Route::get('/test-wasabi', function () {
//     Storage::disk('wasabi')->put('test.txt', 'It works!', 'public');
//     // To retrieve the file contents
//     $contents = Storage::disk('wasabi')->get('test.txt');

//     // To get the URL (if permissions allow)
//     $url = Storage::disk('wasabi')->url('test.txt');

//     return $url;
// });

// Upload Chunk
// Route::post('/upload-chunk', [CreatePublicationController::class, 'uploadChunk']);

// Video processing routes
Route::post( '/upload-chunk', [CreatePublicationController::class, 'uploadChunk'] );
// Route::post('/check-chunks-status', [CreatePublicationController::class, 'checkChunksStatus']);
Route::post( '/finalize-video', [CreatePublicationController::class, 'finalizeVideo'] );
// Route::post('/cleanup-temp-files', [CreatePublicationController::class, 'cleanupTempFiles']);
// Route::get('/check-video-status/{fileId}', [CreatePublicationController::class, 'checkVideoStatus']);

// profile flink api
Route::post( 'profile', [ProfileController::class, 'getStats'] );
Route::post( 'profile/posts', [ProfileController::class, 'getPosts'] );
Route::post( 'profile/boutique_posts', [ProfileController::class, 'getBoutiquePosts'] );
Route::post( 'profile/flink_catalogues', [CatalogueController::class, 'getProfileCatalogues'] );
Route::post( 'profile/saved_posts', [ProfileController::class, 'getSavedPosts'] );
Route::post( 'profile/reposted_posts', [ProfileController::class, 'getRepostedPosts'] );
Route::post( 'profile/followers', [ProfileController::class, 'getFollowers'] );
Route::post( 'profile/following', [ProfileController::class, 'getFollowing'] );
Route::get( 'profile/follows/suggestions/{profileSlug}', [ProfileController::class, 'getSuggestions'] );

// ads contoller flink
Route::get( '/get_publications_ads', [AdsController::class, 'getPublicationsAds'] );
Route::get( '/get_publications_boosted', [AdsController::class, 'getBoostedPublicationsOnly'] );


// Annonce Controller Routes 
Route::get( '/annonces', [AnnonceController::class, 'AllAnnonces'] );
// Route::get('/annonces_test', [AnnonceController::class, 'AllAnnoncesTest']);
Route::get( '/annoncesVideo', [AnnonceController::class, 'AllAnnoncesVideo'] );
// Route::get('/annoncesVideoFilters/{categorieId}', [AnnonceController::class, 'AnnoncesVideoFilters']);
// Route::get('/annonce/slug/{slug}', [AnnonceController::class, 'AnnonceDetails']);
// Route::get('/annonces_booster', [AnnonceController::class, 'getAnnoncesBooster']);
// Route::get('/get_annonces_emplois', [AnnonceController::class, 'AnnonceEmplois']);
// Route::get('/publicite_text', [AnnonceController::class, 'getPubliciteText']);
// Route::get('/get_immo_caractiristiques', [AnnonceController::class, 'getImmoCaractiristique']);
// Route::get('/get_popular_search_keywords/{categorie}/{regions}/{villes}', [AnnonceController::class, 'getTopTenMarketplaceSearch']);


// EventStatisticController ayoub routes with annonce
// Route::post('/annonce/{id}/increment-view', [EventStatisticController::class, 'incrementViewCount']);
// Route::post('/annonce/{id}/increment-click-sur-tele', [EventStatisticController::class, 'incrementClickSurTeleCount']);
// Route::post('/annonce/{id}/increment-favoris', [EventStatisticController::class, 'incrementFavorisCount']);
// Route::post('/annonce/{id}/increment-click-sur-message', [EventStatisticController::class, 'incrementClickSurMessageCount']);
// Route::post('/annonce/{id}/increment-vue-video', [EventStatisticController::class, 'incrementVueVideoCount']);
// Route::post('/annonce/{id}/increment-click-sur-what', [EventStatisticController::class, 'incrementClickSurWhatCount']);
// Route::post('/annonce/{id}/decrement-favoris', [EventStatisticController::class, 'decrementFavorisCount']);
Route::post( '/publicite/{id}/increment-sponsored-click', [EventStatisticController::class, 'incrementSponsoredClick'] );
Route::post( '/publicite/{id}/increment-sponsored-view', [EventStatisticController::class, 'incrementSponsoredView'] );
// Route::post('/annonce/{id}/increment-view-details', [EventStatisticController::class, 'incrementViewDetailsCount']);

// EventStatisticController ayoub routes with boutique
// Route::post('/boutique/{id}/increment-view', [EventStatisticController::class, 'incrementViewCountBoutique']);
// Route::post('/boutique/{id}/increment-click-sur-tele', [EventStatisticController::class, 'incrementClickSurTeleCountBoutique']);
// Route::post('/boutique/{id}/increment-click-itineraire', [EventStatisticController::class, 'incrementClickItineraireCountBoutique']);



// Filter Controller Routes 
// Route::get('/villes', [FilterController::class, 'getVilles']);
// Route::get('/categories', [FilterController::class, 'getCategories']);
// Route::get('/regions', [FilterController::class, 'getRegions']);
// Route::get('/annonce_by_Category_mobile/{id}', [FilterController::class, 'AnnonceByCategoryMobile']);
// Route::get('/annonce_by_Category_desktop/{id}', [FilterController::class, 'AnnonceByCategoryDesktop']);
// Route::get('/locations', [FilterController::class, 'getLocations']);
// Route::get('/categories_google', [FilterController::class, 'getCategories_Google']);

// Route::get('/villes/{ville}', [FilterController::class, 'getVille']);
// Route::get('/categories/{categorie}', [FilterController::class, 'getCategorie']);
// Route::get('/regions/{ville}/{region}', [FilterController::class, 'getRegion']);
// Route::get('/categories_google/{categorie}', [FilterController::class, 'getCategorie_Google']);

// boutique / DetailsBoutiqueController Routes
// Route::get('/boutiques', [DetailsBoutiqueController::class, 'getAllBoutiques']);

// get tele informations
// Route::get('/get_tele_marques', [AnnonceController::class, 'getTeleMarques']);
// Route::get('/get_tele_models/{id}', [AnnonceController::class, 'getTeleModels']);

// get vetements informations
// Route::get('/get_type_vetements', [AnnonceController::class, 'getTypeVetements']);
// Route::get('/get_taille_vetements', [AnnonceController::class, 'getTailleVetements']);

// get search keywords
// Route::get('/get_search_keywords', [FilterController::class, 'getSearchKeyword']);

// get slug region
// Route::get('/get_slug_region', [FilterController::class, 'getSlugRegion']);

// get annonces and products
// Route::get('/get_annonces_produits', [AnnonceController::class, 'getAnnoncesProducts']);
// Route::get('/get_annonces_produits_booster', [AnnonceController::class, 'getAnnoncesProductsBooster']);

// Route::get('/get_annonces_regions_urls', [AnnonceController::class, 'getAnnoncesRegionsUrls']);
// Route::get('/get_annonces_villes_urls', [AnnonceController::class, 'getAnnoncesVillesUrls']);
// Route::get('/get_annonces_regions_only_urls', [AnnonceController::class, 'getAnnoncesRegionsOnlysUrls']);
// Route::get('/get_boutiques_urls', [DetailsBoutiqueController::class, 'getFilterUrls']);


// Test routes
// Route::get('/testBuilderApi', [FilterController::class, 'TestMethod']);
// Route::post('/get_ip', [AnnonceController::class, 'getIpAdresse']);


// Custom Flux API routes
Route::post( '/custom-flux/create', [CustomFluxController::class, 'create'] );
Route::post( '/custom-flux/list', [CustomFluxController::class, 'list'] );
Route::post( '/custom-flux/available-data', [CustomFluxController::class, 'getAvailableData'] );
Route::post( '/custom-flux/terms-search', [CustomFluxController::class, 'termsSearch'] );
Route::post( '/custom-flux/publications', [CustomFluxController::class, 'index'] );
Route::post( '/custom-flux/update', [CustomFluxController::class, 'update'] );
Route::post( '/custom-flux/delete', [CustomFluxController::class, 'delete'] );

Route::get( '/get_home_publications', [HomeController::class, 'index'] );
Route::get( '/test/get_publications', [PublicationController::class, 'indexTest'] );

// Email bounce webhook - will be accessible at:
Route::post( '/webhooks/email-bounce', [BounceEmailController::class, 'handleIncomingEmail'] )
    ->name( 'api.webhooks.email-bounce' )->middleware( 'auth.webhook' ); // ✅ Use the alias here

require __DIR__ . '/auth.php';