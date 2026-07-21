<?php

namespace App\Http\Controllers\builder;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\BuilderService;
use App\Models\User;
use App\Models\Etablissement;

class BuilderController extends Controller
{
    // public function ProjectWebsites($projectId) {
    //     try {
    //         $builderService = new BuilderService();
    //         $websitesByProject = $builderService->getProjectWebsites($projectId);
    //         return response()->json($websitesByProject);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
        
    // }

    // public function openSession(Request $request) {
    //     $userEmail = $request->query('userEmail');
    //     $projectId = $request->query('projectId');
    //     $websiteId = $request->query('websiteId');
    //     $path = $request->query('path');
    //     try {
    //         $builderService = new BuilderService();
    //         if($userEmail && $projectId && $websiteId && $path) {
    //             $dataOpenSession = [
    //                 'userEmail' => $userEmail,
    //                 'projectId' => $projectId,
    //                 'websiteId' => $websiteId,
    //                 'path' => $path,
    //             ];
    //             $openSession = $builderService->openSession($dataOpenSession);
    //             return response()->json($openSession);
    //         }
            
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function createAccount(Request $request, $userEtabId, $type) {
        
    //     if($type && $type == "user") {
    //         $user = User::find($userEtabId);
    //         $builderService = new BuilderService();
            
    //         if($user) {
    //             $dataCreateUser = [
    //                 'name'=> $user->first_name . " " . $user->last_name, 
    //                 'email'=> $user->email, 
    //                 'locale'=> 'ma',
    //             ];
    //             $createUser = $builderService->createUser($request, $dataCreateUser);

    //             if($createUser['data']) {
    //                 $dataCreateProject = [
    //                     'templateId' => null,
    //                     'funnelTemplateId' => null,
    //                     'userId' => $createUser['data']['id'],
    //                     'customerFirstName' => $user->first_name,
    //                     'customerLastName' => $user->last_name,
    //                     'customerEmail' => $user->email,
    //                     // 'customerSubdomain' => 'domaine2024',
    //                     // 'websiteName' => 'website2024',
    //                 ];
        
    //                 $createProject = $builderService->createAccount($dataCreateProject);

    //                 if($createProject['data']) {
    //                     $user->builder_user_id = $createUser['data']['id'];
    //                     $user->builder_project_id = $createProject['data']['projectId'];
    //                     $user->save();
    //                     return response()->json(["status"=>201, "data"=>$user]);
    //                 }
    //             }
    //         }
            
    //     } else {
    //         $etab = Etablissement::find($userEtabId);
    //         $builderService = new BuilderService();
            
    //         if($etab) {
    //             $dataCreateUser = [
    //                 'name'=> $etab->nom, 
    //                 'email'=> $etab->email, 'locale'=> 'ma'
    //             ];
    //             $createUser = $builderService->createUser($request, $dataCreateUser);

    //             if($createUser['data']) {
    //                 $dataCreateProject = [
    //                     'templateId' => null,
    //                     'funnelTemplateId' => null,
    //                     'userId' => $createUser['data']['id'],
    //                     'customerFirstName' => "",
    //                     'customerLastName' => $etab->nom,
    //                     'customerEmail' => $etab->email,
    //                     // 'customerSubdomain' => 'domaine2024',
    //                     // 'websiteName' => 'website2024',
    //                 ];
        
    //                 $createProject = $builderService->createAccount($dataCreateProject);

    //                 if($createProject['data']) {
    //                     $etab->builder_user_id = $createUser['data']['id'];
    //                     $etab->builder_project_id = $createProject['data']['projectId'];
    //                     $etab->save();
    //                     return response()->json(["status"=>201, "data"=>$etab]);
    //                 }
    //             }
    //         }
    //     }
    // }
    // // ajouter web site or funnel
    // public function AddWebsite(Request $request) {
        
    //     $projectId = $request->query('projectId');
    //     $customerSubdomain = $request->query('customerSubdomain');
    //     try {
    //         $builderService = new BuilderService();
    //         if($projectId) {
    //             $dataCreateWebsite = [
    //                 'projectId' => $projectId,
    //                 'templateId' => null,
    //                 'funnelTemplateId' => null,
    //                 'customerSubdomain' => $customerSubdomain,
    //             ];
    //             $createWebsite = $builderService->createWebsite($dataCreateWebsite);
    //             return response()->json($createWebsite);
    //         } else return response()->json(["status"=>404, "data"=>"project not found"]);
            
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }
}
