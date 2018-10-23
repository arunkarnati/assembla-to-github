## Migrate Assembla tickets to issues in GitHub

**Follow the steps**

* Save the dump file (or bak file) exported from Assembla to the root folder with name dump.json
* If you did not name the file dump.json, then update the `DUMP_FILE_NAME` constant value in `Execute` class file with the file name used. 
* Manually create milestones in GitHub and note down the IDs.
* Take a note of Assembla milestone IDs.
* Populate `MILESTONE_MAP` constant in `Execute` class accordingly.
* Update `ASSEMBLA_WORKSPACE` constant.
* Copy `.env.dist` file and replace the `_HERE` with actual values.
* Make sure you have permission to the repo for adding issues.
* Since this is coded as a library, go to `ExecuteTest` class file and run `testCreateIssuesOnGitHub` to import the tickets.
* A couple of tests also provided to test if the reading of dump file and creating tickets work as expected
 
