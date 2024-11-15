<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Twilio\Rest\Client;
use Illuminate\Support\Facades\Cache;

class WhatsAppBotController extends Controller
{
    private $twilio;

    public function __construct()
    {
        $this->twilio = new Client(env('TWILIO_SID'), env('TWILIO_AUTH_TOKEN'));
    }

    public function handleIncomingMessage(Request $request)
    {
        $customerMessage = strtolower(trim($request->input('Body')));
        $from = $request->input('From');

        if (!$from) {
            \Log::error('The "From" field is missing in the request.');
            return response()->json(['error' => 'Missing sender information'], 400);
        }

        // Retrieve or initialize the conversation state for this user
        $sessionData = Cache::get($from, ['step' => 'greeting']);
        $sessionData = Cache::get($from, ['step' => 'greeting']);

        // Determine response based on the current step
        $responseMessage = $this->getBotResponse($customerMessage, $sessionData, $from);
        // Update the conversation state in the cache
        Cache::put($from, $sessionData, 60); // Store for 1 hour

        // Send the response to the customer
        $this->sendMessage($from, $responseMessage);

        return response()->json(['status' => 'Message sent']);
    }

    private function getBotResponse($customerMessage, &$sessionData, $from)
    {
        switch ($sessionData['step']) {
            case 'greeting':
                $sessionData['step'] = 'ask_name';
                return "Hello! Welcome to our service. Can I know your name ?";

            case 'ask_name':
                $sessionData['name'] = $customerMessage;
                $sessionData['step'] = 'ask_policy_period';
                return "Thanks, {$sessionData['name']}! Could you please provide the policy period in [1, 2, 3] (In Year(s)) ?";

            case 'ask_policy_period':
                $sessionData['policy_period'] = $customerMessage;
                if (in_array($sessionData['policy_period'], [1, 2, 3])) {
                    $sessionData['step'] = 'ask_plan_type';
                    return "Thank you! Select Plan Type \n 1. Individual \n 2. Floater.";
                } else {
                    return "Incorrect Policy period ! Could you please provide the policy period in [1, 2, 3] (In Year(s)) ?";
                }

            case 'ask_plan_type':
                $sessionData['plan_type'] = $customerMessage;
                if (in_array($sessionData['plan_type'], [1, 2])) {
                    $sessionData['plan_type'] = $sessionData['plan_type'] == 1 ? 'I' : 'F';
                    return "Thank you! Select Plan Type \n 1. Male \n 2. Female.";
                }
                $sessionData['step'] = 'complete';

            case 'complete':
                // Clear the cache for this user after completion
                Cache::forget($from);
                $sessionData['step'] = 'greeting';
                return "Thank you! If you have any further questions, feel free to ask.";

            default:
                // Handle any unexpected step
                Cache::forget($from); // Ensure any incomplete session is cleared
                $sessionData['step'] = 'greeting';
                return "Thank you! Let's start over. Please say 'hi' to begin.";
        }
    }


    private function sendMessage($to, $message)
    {
        try {
            $this->twilio->messages->create($to, [
                'from' => env('TWILIO_WHATSAPP_NUMBER'),
                'body' => $message
            ]);
        } catch (\Exception $e) {
            \Log::error('Error sending message: ' . $e->getMessage());
        }
    }
}

