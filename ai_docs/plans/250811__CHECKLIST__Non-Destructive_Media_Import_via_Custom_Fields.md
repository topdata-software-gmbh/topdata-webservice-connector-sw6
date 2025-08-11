# Checklist: Implement Non-Destructive Media Import

This checklist guides the implementation of tagging imported media with custom fields to prevent the deletion of manually uploaded images.

## Phase 1: Database Schema Setup

-   [x] **Create Migration File:**
    -   [x] Create a new file in `src/Migration/`.
    -   [x] Name it `Migration[TIMESTAMP]AddCustomFieldsForTagging.php`, using the current Unix timestamp.
-   [x] **Populate Migration File:**
    -   [x] Copy the complete PHP code for the `Migration...AddCustomFieldsForTagging` class from the implementation plan into the newly created file.
    -   [x] Ensure the `getCreationTimestamp()` method returns the correct timestamp matching the filename.

## Phase 2: Tagging Imported Media

-   [ ] **Modify `MediaHelperService`:**
    -   [ ] Open the file `src/Service/MediaHelperService.php`.
    -   [ ] Locate the `createMediaInFolder()` method.
    -   [ ] Add the `customFields` array to the `$this->mediaRepository->create()` call as specified in the plan.

## Phase 3: Implementing Selective Media Deletion

-   [ ] **Update `MediaHelperService` Deletion Logic:**
    -   [ ] Open the file `src/Service/MediaHelperService.php`.
    -   [ ] Locate the `unlinkImages()` method.
    -   [ ] Replace the entire body of the `unlinkImages()` method with the new implementation that uses the selective `DELETE` query.

## Phase 4: Documentation and Versioning

-   [ ] **Update `composer.json`:**
    -   [ ] Open the `composer.json` file.
    -   [ ] Change the `"version"` from `"8.1.1"` to `"8.2.0"`.
-   [ ] **Update `CHANGELOG.md`:**
    -   [ ] Open the `CHANGELOG.md` file.
    -   [ ] Add the new `## [8.2.0]` release notes to the top of the file.
-   [ ] **Update `README.md`:**
    -   [ ] Open the `README.md` file.
    -   [ ] Add the new "Non-Destructive Image Updates" section under "Advices and examples".

## Final Verification

-   [ ] All code changes have been applied correctly.
-   [ ] All documentation and versioning files are updated.
-   [ ] The project is ready for testing the new feature.

