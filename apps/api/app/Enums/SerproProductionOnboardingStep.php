<?php

namespace App\Enums;

enum SerproProductionOnboardingStep: string
{
    case ValidateInput = 'VALIDATE_INPUT';
    case StorePending = 'STORE_PENDING';
    case VerifyVault = 'VERIFY_VAULT';
    case TestOauth = 'TEST_OAUTH';
    case ConfirmCutover = 'CONFIRM_CUTOVER';
    case ActivateAuthorization = 'ACTIVATE_AUTHORIZATION';
    case QueueReadSync = 'QUEUE_READ_SYNC';
    case Completed = 'COMPLETED';
}
