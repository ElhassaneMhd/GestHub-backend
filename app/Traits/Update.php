<?php

namespace App\Traits;
use App\Models\Intern;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

trait Update
{
    use Refactor;
    public function updateProfile($data,$profile){
        $validatedData = $data->validate([
            'email' => 'email',
            'firstName' =>'string',
            'lastName' =>'string',
            'phone' =>'string',
            'gender' =>'string|in:M,Mme',
            'password' => [
                'string',
                Password::min(8)->numbers(),
                'confirmed',
            ]
        ]);
        if ($profile->email!==$data['email']){
            $validatedData = $data->validate([
                'email' => 'email|unique:profiles,email',
                'firstName' =>'string',
                'lastName' =>'string',
                'phone' =>'string',
                'gender' =>'string|in:M,Mme',
                'password' => [
                    'string',
                    Password::min(8)->numbers(),
                    'confirmed',
                ],
            ]);
        }
        DB::beginTransaction();
        $profile->update($validatedData);
        $isCommited = true;
        $otherData = array_filter([
            'academicLevel' => $data['academicLevel'] ?? null,
            'establishment' => $data['establishment'] ?? null,
            'startDate' => $data['startDate'] ?? null,
            'specialty' => $data['specialty'] ?? null,
            'endDate' => $data['endDate'] ?? null,
            'projectLink' => $data['projectLink'] ?? null,
        ]);
        if ($profile->getRoleNames()[0]=='user') {
            $user = $profile->user;
            $isCommited=$user->update($otherData);
        }
        if ($profile->getRoleNames()[0]=='intern') {
            $intern = $profile->intern;
            $isCommited=$intern->update($otherData);
        }
        if($isCommited){
            DB::commit();
            return response()->json($this->refactorProfile($profile));
        }else{
            DB::rollBack();
            return [];
        }
    }
    public function updateProfilePassword($request,$profile){
        $validatedData = $request->validate([
                    'currentPassword' => [
                            'required',
                            Password::min(8)->numbers(),
                        ]  ,
                    'password' => [
                            'string',
                            'required',
                            Password::min(8)->numbers(),
                            'confirmed',
                        ]
                    ]);
    if (Hash::check($validatedData['currentPassword'], $profile->password)) {
        if (Hash::check($validatedData['password'], $profile->password)) {
                return response()->json(['message' => 'Please enter a new password '], 400);
            }
        $hashedPassword = Hash::make($validatedData['password']);
        $profile->password = $hashedPassword;
        $profile->save();
        return response()->json(['message' => ' Password updated successfully'], 200);
    }
    return response()->json(['message' => 'Incorrect current password'], 400);
    }
    public function updateProject($data,$project){
        $tasks=$project->tasks;
        $validatedProject = $data->validate([
            'subject' => 'string',
            'description' => 'string',
            'startDate' => 'date',
            'endDate' => 'date',
            'status' => 'string',
            'priority' => 'in:Low,Medium,High,None',
            'supervisor_id' => 'exists:supervisors,id',
            'intern_id' => 'nullable|exists:interns,id',
            'teamMembers.*' => 'exists:interns,id',
        ]);
        $project->update($validatedProject);
        foreach($data['teamMembers'] as $teamMemberId){
            if(!in_array($teamMemberId,$project->interns()->pluck('intern_id')->toArray()) ){
            $id = Intern::find($teamMemberId)->profile->id;
            $notifData = [
                'activity'=>'You have been assigned a new project',
                'object'=>$project->subject,
                'action'=>'newProject',
                'receiver'=>$id
            ];
            $this->storeNotification($notifData);

            };

        }
        if ($data->has('teamMembers')){
            foreach($tasks as $task){
                if(!in_array($task->intern_id ,$data['teamMembers'])){
                    $task->intern_id = null;
                    $task->save();
                }
            }
            $project->interns()->detach();
            $project->interns()->attach($data['teamMembers']);
        }
        return $project;
    }
    public function updateTask($request,$task){
        $validatedData = $request->validate([
        'title' => 'nullable|max:255',
        'description' => 'nullable|string',
        'dueDate' => 'nullable|date',
        'priority' => 'in:Low,Medium,High,None',
        'status' => 'in:To Do,Done,In Progress',
        'intern_id' => 'nullable|exists:interns,id',
        'project_id' => 'exists:projects,id',
    ]);

        if(array_key_exists('intern_id', $validatedData)&& $task->intern_id !== $validatedData['intern_id']){
        $id = Intern::find($validatedData['intern_id'])->profile->id;
        $notifData = [
            'activity'=>'You have been assigned a new task',
             'object'=>$task->project->subject. '/' . $task->title,
             'action'=>'newTask',
            'receiver'=>$id
            ];
        $this->storeNotification($notifData);

        }
        $task->update($validatedData);
        $this->updateProjectStatus($task->project_id);
        return $task;
    }
    public function updateProjectStatus($project_id){
        $project = Project::find($project_id);
        $todoCount = $project->tasks()->where('status', 'To Do')->count();
        $progressCount = $project->tasks()->where('status', 'In Progress')->count();
        $doneCount = $project->tasks()->where('status', 'Done')->count();
        if ($doneCount > 0 && $todoCount == 0 && $progressCount == 0) {
            $project->status = "Completed";
               $teamMembers = $project->interns;
            $supervisor = $project->supervisor;
            foreach($teamMembers as $teamMember){
                $notifData = [
                    'activity'=>'One of your assigned projects is completed',
                    'object'=>$project->subject,
                    'action'=>'completedProject',
                    'receiver'=>$teamMember->profile->id
                ];
                $this->storeNotification($notifData);
            }
            $notifData = [
                    'activity'=>'One of your assigned projects is completed',
                    'object'=>$project->subject,
                    'action'=>'completedProject',
                    'receiver'=>$supervisor->profile->id
                ];
            $this->storeNotification($notifData);

        } elseif ($progressCount > 0 || $doneCount > 0) {
            $project->status = "In Progress";
        } else {
            $project->status = "Not Started";
        }

        $project->save();
    }
    public function updateOffer($request,$offer){
           $updateData = array_filter([
                "title"=>   $request['title'] ?? null,
                "description"=>   $request['description'] ?? null,
                'sector'=> $request['sector'] ?? null,
                'experience'=> $request['experience'] ?? null,
                'skills'=>  $request['skills'] ?? null,
                'duration'=>  $request['duration'] ?? null,
                'company'=>  $request['company'] ?? null,
                'visibility'=>  $request['visibility'] ?? null,
                'status'=> $request['status'] ?? null,
                'city'=>  $request['city'] ?? null,
                'type'=> $request['type'] ?? null,
            ]);
        $offer->update($updateData);
        return $offer;
    }
    public function updateApplication($request,$application){
         $updateData = array_filter([
                "user_id"=>   $request['user_id'] ?? null,
                "offer_id"=>   $request['offer_id'] ?? null,
                'startDate'=> $request['startDate'] ?? null,
                'endDate'=> $request['endDate'] ?? null,
            ]);
        $application->update($updateData);
        return $application;
    }
    public function processApplication($application,$traitement){
        $profile = $application->user->profile;
        if ($application->status !== 'Pending'){
            return response()->json(['message' => 'application alraedy processed'], 404);
        }
        if($traitement==='approve'){
            $application->status = 'Approved';
            $application->save();
            $data = ['action' => 'Approve', 'model' => 'Application', 'activity'=>'Approve Application for : ','object'=>$profile->firstName .' '.$profile->lastName .' --> '.$application->offer->title ];
            $notifData = [
                'activity'=>'Your application has been approved ',
                'object'=>$application->offer->title,
                'action'=>'acceptedApplication',
                'receiver'=>$profile->id
                ];
                $MailData = [
                    'to'=>$profile->email,
                    'subject'=>'Application Approved',
                    'message'=>"Congratulations 👏,  your application has been approved for the " .$application->offer->title ." position! Your skills and experience align perfectly with our requirements,
                         and we're excited to have you join our team. We look forward to working together and seeing your contributions to our project."
                ];
            $this->sendEmail($MailData);
            $this->storeNotification($notifData);
            $this->storeActivite($data);

            return response()->json(['message' => 'application approved succeffully'], 200);
        }
        if($traitement==='reject'){
            $application->status='Rejected';
            $application->save();
            $data = ['action' => 'Reject', 'model' => 'Application', 'activity'=>'Rejecte Application for : ','object'=>$profile->firstName .' '.$profile->lastName .' --> '.$application->offer->title ];
            $this->storeActivite($data);
            return response()->json(['message' => 'application rejected succeffully'], 200);
        }
    }
    public function updateSession($session){
        $session->status = 'Offline';
        $session->save();
    }
}
