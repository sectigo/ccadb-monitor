# CCADB Compliance Monitor

This repository contains tools and scripts for monitoring compliance with the CCADB Policy, specifically related to the disclosed CRLs and CP/CPS URLs.

The tools available allow running this toolset either as standalone scripts, including support for running as GitHub Actions, as well as an integrated setup including a WebUI.

## Supported Checks
- Verify that all disclosed CRLs are accessible (Return a 200 HTTP status code).
- Verify that all disclosed CP/CPS URLs are accessible (Return a 200 HTTP status code).

**Note:** This toolset does not currently validate the contents of the CRLs or CP/CPS documents, only their accessibility. Additional verifications however are being planned.

## Usage

### Standalone mode
In standalone mode, there are two scripts available to run:

#### VerifyCPCPSURLs
This script checks the accessibility of CP/CPS URLs disclosed in the CCADB. To run the check, execute the following command:
> php artisan verify:urls:cpcps --caowner="YOURCCADBName" --mode=Standalone

#### VerifyCRLURLs
This script checks the accessibility of CRL URLs disclosed in the CCADB. To run the check, execute the following command:
> php artisan verify:urls:crl --caowner="YOURCCADBName" --mode=Standalone

### Standalone mode as GitHub Action
You can also run the standalone scripts as GitHub Actions. Workflow files are provided in `.github/workflows/verify-cpcps-urls.yml` and `.github/workflows/verify-crl-urls.yml`.
Please make sure to set the following value within both actions:

- default: Your CCADB CA Name

Additionally, to enable alerting, consider setting up the following secrets or values:
- LOG_STACK: 'teams' or 'slack' (depending on your logging preference)
- LOG_SLACK_WEBHOOK_URL: The webhook URL for your Slack logging channel
- LOG_TEAMS_WEBHOOK_URL: The webhook URL for your Microsoft Teams logging channel
- LOG_LEVEL: The logging level (e.g., 'info', 'warning', 'error'). "error" is recommended.

Finally, you should set a custom execution schedule for the GitHub Actions to run periodically. The default is set to run on-demand.

### Integrated mode
In integrated mode, you can utilize a pre-built Docker image that includes a WebUI for easier management and monitoring. It already has the standalone scripts integrated and runs these on an hourly schedule. 

Integrated mode also utilizes a database to store historical results and store parts of the CCADB CSV files for faster processing. For additional convenience, a docker-compose.yml file is provided to set up the entire environment quickly.
