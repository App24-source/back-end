<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BuilderService
{
    // create new user 
    public function createUser($request, $data)
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-CLIENT-KEY' => config('services.builder.api_key'),
        ])->asForm()  // Use application/x-www-form-urlencoded
        ->post('https://api.pro.app24.ma/api/v1/users', [
            'name' => $data['name'],
            'email' => $data['email'],
            // 'password' => $data['password'],  // Optional, if provided
            'locale' => $data['locale'] ?? 'en',  // Default to 'en' if not provided
            'ip' => $request->ip(),  // Use the user's IP or provided IP
        ]);

        // Check if the request was successful
        if ($response->successful()) {
            return $response->json(); // Return the response data
        }

        // Handle errors (e.g., log the error)
        return [
            'error' => true,
            'message' => $response->body(),
        ];
    }

    // create new account
    public function createAccount($data)
    {
        $response = Http::withHeaders([
            'X-CLIENT-KEY' => config('services.builder.api_key'),
        ])->asForm()  // Use application/x-www-form-urlencoded
        ->post('https://api.pro.app24.ma/api/v1/website', [
            'templateId' => $data['templateId'],
            'funnelTemplateId' => $data['funnelTemplateId'],
            'userId' => $data['userId'],
            'customerFirstName' => $data['customerFirstName'],
            'customerLastName' => $data['customerLastName'],
            'customerEmail' => $data['customerEmail'],
            // 'customerSubdomain' => $data['customerSubdomain'],
            // 'websiteName' => $data['websiteName'],
        ]);

        // Check if the request was successful
        if ($response->successful()) {
            return $response->json(); // Return the response data
        }

        // Handle errors (e.g., log the error)
        return [
            'error' => true,
            'message' => $response->body(),
        ];
    }

    // create new website
    public function createWebsite($data)
    {
        $response = Http::withHeaders([
            'X-CLIENT-KEY' => config('services.builder.api_key'),
        ])->asForm()  // Use application/x-www-form-urlencoded
        ->post('https://api.pro.app24.ma/api/v1/website/add', [
            'projectId' => $data['projectId'],
            'templateId' => $data['templateId'],
            'funnelTemplateId' => $data['funnelTemplateId'],
            'customerSubdomain' => $data['customerSubdomain'],
        ]);

        // Check if the request was successful
        if ($response->successful()) {
            return $response->json(); // Return the response data
        }

        // Handle errors (e.g., log the error)
        return [
            'error' => true,
            'message' => $response->body(),
        ];
    }

    // get all websites of a project
    public function getProjectWebsites($projectId)
    {
        $response = Http::withHeaders([
            'X-CLIENT-KEY' => config('services.builder.api_key'),
        ])->get("https://api.pro.app24.ma/api/v1/projects/{$projectId}/websites");

        // Check if the request was successful
        if ($response->successful()) {
            return $response->json(); // Return the response data
        }

        // Handle errors (e.g., log the error)
        return [
            'error' => true,
            'message' => $response->body(),
        ];
    }

    // open a session
    public function openSession($data)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.builder.api_key'),
        ])->asForm()  // Use application/x-www-form-urlencoded
        ->post('https://api.pro.app24.ma/api/platform/session', [
            'userEmail' => $data['userEmail'],
            'projectId' => $data['projectId'],
            'websiteId' => $data['websiteId'],
            'path' => $data['path'],
        ]);

        // Check if the request was successful
        if ($response->successful()) {
            return $response->json(); // Return the response data
        }

        // Handle errors (e.g., log the error)
        return [
            'error' => true,
            'message' => $response->body(),
        ];
    }

    // list plans
    public function listPlans()
    {
        $response = Http::withHeaders([
            'X-CLIENT-KEY' => config('services.builder.api_key'),
        ])->get('https://api.pro.app24.ma/api/v1/plans');

        // Check if the request was successful
        if ($response->successful()) {
            return $response->json(); // Return the response data
        }

        // Handle errors (e.g., log the error)
        return [
            'error' => true,
            'message' => $response->body(),
        ];
    }

    // get project details
    public function projectDetails($projectId)
    {
        $response = Http::withHeaders([
            'X-CLIENT-KEY' => config('services.builder.api_key'),
        ])->get("https://api.pro.app24.ma/api/v1/projects/{$projectId}");

        // Check if the request was successful
        if ($response->successful()) {
            return $response->json(); // Return the response data
        }

        // Handle errors (e.g., log the error)
        return [
            'error' => true,
            'message' => $response->body(),
        ];
    }

    // check Subscription Plan
    public function checkSubscriptionPlan($websiteId, $planId)
    {
        $response = Http::withHeaders([
            'X-CLIENT-KEY' => config('services.builder.api_key'),
        ])->asForm()  // Use application/x-www-form-urlencoded
        ->get("https://api.pro.app24.ma/api/v1/website/{$websiteId}/check-limits", [
            "planId"=> $planId
        ]);

        // Check if the request was successful
        if ($response->successful()) {
            return $response->json(); // Return the response data
        }

        // Handle errors (e.g., log the error)
        return [
            'error' => true,
            'message' => $response->body(),
        ];
    }

    // Activate Project for period
    public function activeProjectPeriod($projectId, $data)
    {
        $response = Http::withHeaders([
            'X-CLIENT-KEY' => config('services.builder.api_key'),
        ])->asForm()  // Use application/x-www-form-urlencoded
        ->post("https://api.pro.app24.ma/api/v1/projects/:$projectId/activate", [
            "planId" => $data['planId'],
            "forMonths" => $data['forMonths'],
            "continueAs" => $data['continueAs'],
        ]);

        // Check if the request was successful
        if ($response->successful()) {
            return $response->json(); // Return the response data
        }

        // Handle errors (e.g., log the error)
        return [
            'error' => true,
            'message' => $response->body(),
        ];
    }
}
