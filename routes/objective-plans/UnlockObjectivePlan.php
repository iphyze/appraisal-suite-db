<?php
require_once __DIR__ . '/../../includes/ApiException.php';
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/ObjectivePlanHelpers.php';
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Bad Request: Only POST method is allowed', 405);
    $userData = requireRoles(['super_admin','admin','supervisor']);
    $loggedInUserId = (int)$userData['id'];
    $loggedInEmail = trim((string)($userData['email'] ?? 'User'));
    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format.', 400);
    $staffUserId=(int)($data['staff_user_id']??0); $sectionId=(int)($data['section_id']??0);
    $ctx=opResolveContext($conn,$userData,$staffUserId,$sectionId,false,true);
    if (empty($ctx['actor']['can_unlock'])) throw new Exception('Only the assigned supervisor/HOD can unlock this employee\'s objective plan.',403);
    $conn->begin_transaction();
    try {
        $appraisal=opFindSubmittedAppraisal($conn,(int)$ctx['section']['cycle_id'],$staffUserId,true);
        $reason=null;
        if ($appraisal) {
            apAssertSupervisorUpdateEligible(apCanSupervisorUpdateAppraisal($conn,$userData,null,$appraisal),true);
            $reason=opObjectiveUnlockReason($data['reason'] ?? null);
        }
        $plan=opGetPlanForUpdate($conn,$sectionId,$staffUserId);
        if (!$plan) throw new Exception('No objective plan exists for this employee.',404);
        if ($plan['status']!=='locked') throw new Exception('Objective plan is already editable.',409);
        if ($appraisal) {
            $before=opSubmittedObjectiveSnapshot($conn,(int)$appraisal['id'],$sectionId);
            opStoreObjectiveUnlockEvent($conn,$appraisal,$plan,$loggedInUserId,$reason,$before);
        }
        $stmt=$conn->prepare("UPDATE staff_objective_plans SET status='draft', locked_at=NULL, locked_by=NULL, updated_by=? WHERE id=?");
        $stmt->bind_param('ii',$loggedInUserId,$plan['id']);
        if(!$stmt->execute()) throw new Exception('Unable to unlock objective plan: '.$stmt->error,500);
        $stmt->close();
        $staffName=trim((string)($ctx['staff']['fullname'] ?: (($ctx['staff']['first_name']??'').' '.($ctx['staff']['last_name']??''))));
        $description="{$loggedInEmail} unlocked the objective plan for {$staffName}.";
        if ($appraisal) $description.=' Appraisal #'.(int)$appraisal['id'].'; reason: '.$reason;
        opAudit($conn,(int)$ctx['section']['company_id'],$loggedInUserId,'unlock_objective_plan',(int)$plan['id'],$description);
        $conn->commit();
    } catch(Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    echo json_encode(['status'=>'Success','message'=>'Objective plan unlocked for editing.','data'=>opPayload($conn,$ctx)]);
} catch(Throwable $e){$code=(int)$e->getCode();$code=($code>=400&&$code<=599)?$code:500;http_response_code($code);echo json_encode(apiErrorPayload($e));}
