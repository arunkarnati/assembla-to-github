## Migrate Assembla tickets to issues in GitHub

**Migration Notes**

* Save the dump file (or bak file) exported from [Assembla](https://app.assembla.com/) to the root folder with name dump.json
* Manually create milestones in GitHub projects and note down the IDs.
* Take a note of Assembla milestone IDs.
* Populate `MILESTONE_MAP` constant in `Execute` class accordingly.
* Populate `SECOND_MILESTONE_MAP` constant in `Execute` class accordingly.
* Update `ASSEMBLA_WORKSPACE` constant.
* Copy `.env.dist` file and replace the values ending with `_HERE` with  the actual values.
* Make sure you have permission to the repo for adding issues.

**Migration process is broken into two steps**

**Step 1**

* Go to `ExecuteTest` class file and run `testReadDumpFile`, this creates two files `tickets.json` and `milestones.json`.
* check `debug.log` file in the root folder for more info on how many tickets will be imported. 

**Step 2**

* run `testCreateIssuesOnGitHub` to create the tickets on GitHub.
* A couple of tests also provided to test if the reading of dump file and creating tickets work as expected
* `tail -f debug.log` to keep track of the creation
* `log_table.txt` has  the Assembla ticket and GitHub ticket info
 
