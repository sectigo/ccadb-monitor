<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Issue extends Model
{
    protected $fillable = ["ca_certificate_record_id", "url", "error", "first_detected_at", "last_detected_at", "is_resolved", "issue_type"];

    public function createOrUpdateError($caCertificateRecordId, $url, $error, $type, $isResolved = false)
    {
        if ($isResolved) {
            // Mark existing issue as resolved
            $issue = self::where('ca_certificate_record_id', $caCertificateRecordId)
                ->where('url', $url)
                ->where('issue_type', $type)
                ->where('is_resolved', false)
                ->first();

            if ($issue) {
                $issue->is_resolved = true;
                $issue->last_detected_at = now();
                $issue->save();
            }
        } else {
            // Create or update issue
            $issue = self::where('ca_certificate_record_id', $caCertificateRecordId)
                ->where('url', $url)
                ->where('issue_type', $type)
                ->where('is_resolved', false)
                ->first();

            if ($issue) {
                // Update existing issue
                $issue->error = $error;
                $issue->last_detected_at = now();
                $issue->save();
            } else {
                // Create new issue
                self::create([
                    'ca_certificate_record_id' => $caCertificateRecordId,
                    'url' => $url,
                    'error' => $error,
                    'issue_type' => $type,
                    'first_detected_at' => now(),
                    'last_detected_at' => now(),
                    'is_resolved' => false,
                ]);
            }
        }


    }

}
