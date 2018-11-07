## Migrate Assembla tickets to issues in GitHub

**Follow the steps**

* Save the dump file (or bak file) exported from Assembla to the root folder with name dump.json
* Change the filename to dump.json 
* Manually create milestones in GitHub projects and note down the IDs.
* Take a note of Assembla milestone IDs.
* Populate `MILESTONE_MAP` constant in `Execute` class accordingly.
* Populate `MOBILE_MILESTONE_MAP` constant in `Execute` class accordingly.
* Update `ASSEMBLA_WORKSPACE` constant.
* Copy `.env.dist` file and replace the values ending with `_HERE` with  the actual values.
* Make sure you have permission to the repo for adding issues.
* Go to `ExecuteTest` class file and run `testReadDumpFile`, this creates two files `tickets.json` and `milestones.json` 
* run `testCreateIssuesOnGitHub` to create the tickets on GitHub.
* A couple of tests also provided to test if the reading of dump file and creating tickets work as expected
* Logging is done in `debug.log` file in the root directory
 
