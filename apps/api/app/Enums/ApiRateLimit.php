<?php

namespace App\Enums;

enum ApiRateLimit: string
{
    case InternalCommunicationGateway = 'internal-communication-gateway';
    case CteEmitterPush = 'cte-emitter-push';
    case PublicActivation = 'public-activation';
    case PublicOnboardingStatus = 'public-onboarding-status';
    case PublicOnboardingCompletion = 'public-onboarding-completion';
    case AuthenticatedModerate = 'authenticated-moderate';
    case AuthenticatedStandard = 'authenticated-standard';
    case AuthenticatedSensitive = 'authenticated-sensitive';
    case AuthenticatedCritical = 'authenticated-critical';
    case CommunicationMessageSend = 'communication-message-send';
    case CommunicationConversationListSnapshot = 'communication-conversation-list-snapshot';
    case CommunicationProfilePicture = 'communication-profile-picture';
    case CommunicationGifSearch = 'communication-gif-search';
    case AssistantAccess = 'assistant-access';
    case AssistantChat = 'assistant-chat';
    case CteIntegrationTokenAdministration = 'cte-integration-token-administration';
}
