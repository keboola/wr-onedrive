# Keboola OneDrive Writer

[![Build Status](https://travis-ci.com/keboola/wr-onedrive.svg?branch=master)](https://travis-ci.com/keboola/wr-onedrive)

Exports spreadsheets to OneDrive

## Configuration

The configuration `config.json` contains following properties in `parameters` key: 

- `append` - bool (optional): if sheet exists, rows are appended to the end, default false
- `bulkSize` - int (optional): number of the rows in one batch / insert API call, default `10 000`
- `workbook` - object (required): Workbook `XLSX` file. [Read more](#workbook).
   - One of [`driveId` and `fileId`] or `path` must be configured.
    - `driveId` - string: id of [drive resource](https://docs.microsoft.com/en-us/graph/api/resources/drive?view=graph-rest-1.0)    
    - `fileId` - string: id of [driveItem resource](https://docs.microsoft.com/en-us/graph/api/resources/driveitem?view=graph-rest-1.0)
    - `path` - string: format see below 
    - `metadata` - object (optional): 
       - Serves to store human-readable data when `driveId` / `fileId` are used to define `workbook`.
       - The component code is not using content of this metadata. 
       - UI can use it to store and later show metadata from FilePicker.
- `worksheet` - object (required): Worksheet, one sheet from workbook's sheets. [Read more](#worksheet).
    - One of `id`, `position` or `name` must be configured.
    - If `id` is set, then `position` cannot be set and vice versa, but `name` can always be present.
    - `id` - string: id of [worksheet resource](https://docs.microsoft.com/en-us/graph/api/resources/worksheet?view=graph-rest-1.0)
    - `name` - string: worksheet name
    - `position` - int: worksheet position, first is 0, hidden sheets are included
    - `metadata` - object (optional): 
       - Serves to store human-readable data (eg. sheet name ) when `id` is used to define `worksheet`.
       - The component code is not using content of this metadata.
       - UI can use it to store and later show metadata from FilePicker.

### Workbook
- Specified by one of [`driveId` and `fileId`] or `path` (not both).
- If file doesn't exist and `path` is set, then a new file will be created, otherwise error.
- Parameter `path` can take several forms:
  - **`/path/to/file.xlsx`**
    - The file is searched on a personal OneDrive that belongs to the logged-in account.
  - **`https://...`**
    - The file is searched by sharing link obtained from OneDrive.
    - The copied URL of an opened OneDrive Excel file should also work.
  - **`drive://{driveId}/path/to/file.xlsx`**
    - The file is searched on drive specified with `{driveId}`
    - The `{driveId}` value must be correctly url-encoded
  - **`site://{siteName}/path/to/file.xlsx`**
    - The file is searched on SharePoint site drive specified with `{siteName}`, eg. `Excel Sheets`
    - The `{siteName}` value must be correctly url-encoded

### Worksheet
- Specified by `id`, `position` or `name`.
- Keys `id` and `position` cannot be used together.
- Keys `id` and `position` take precedence over the `name` if it is also set. 
- The sheet is renamed if sheet's and configured `name` is different.  
- If sheet with the configured `name` doesn't exist, then is created.
 

**Examples of `config.json`**

Output workbook and worksheet configured by IDs.
```json
{
  "authorization": {"oauth_api":  "..."},
  "parameters": {
    "append": true,
    "workbook": {
      "driveId": "...",
      "fileId": "..."
    },
    "worksheet": {
      "id": "..."
    }
  }
}
```

Output workbook configured by `path` and worksheet by `position`.  
If the file does not exist, it is created.
```json
{
  "authorization": {"oauth_api":  "..."},
  "parameters": {
    "workbook": {
      "search": "/path/to/my/file.xlsx"
    },
    "worksheet": {
      "position": 0
    }
  }
}
```


## Actions

Read more about actions [in KBC documentation](https://developers.keboola.com/extend/common-interface/actions/).

### Create Workbook

- Action `createWorkbook` serves to create a new workbook - XLSX file.
- Workbook must be defined by `parameters.workbook.path`, for format see [Workbook](#workbook).

**Example `config.json`**:
```json
{
  "authorization": {"oauth_api":  "..."},
  "action": "createWorkbook",
  "parameters": {
    "workbook": {
      "path": "site://Excel+Sheets/path/to/file.xlsx"
    }
  }
}
```

**Example result**:
```json
{
  "file": {
    "driveId": "...",
    "fileId": "..."
  }
}
```

### Create Worksheet

- Action `createWorksheet` serves to create a new worksheet in workbook.
- Parent `workbook` must be defined by [`driveId` and `fileId`] or `path`, see [Workbook](#workbook).
- The new worksheet must be defined by `parameters.worksheet.name`, see [Worksheet](#worksheet).

**Example `config.json`**:
```json
{
  "authorization": {"oauth_api":  "..."},
  "action": "createWorksheet",
  "parameters": {
    "workbook": {
      "driveId": "...",
      "fileId": "..."
    },
    "worksheet": {
      "name": "New Sheet"
    }
  }
}
```

**Example result**:
```json
{
  "worksheet": {
    "driveId": "...",
    "fileId": "...",
    "worksheetId": "..."
  }
}
```

### Search Action

- Action `search` serves to get `driveId` and `fileId` of spreadsheet `XLSX` file.
- Obtained `driveId` and `fileId` can be later used as export target.
- If file is not found, result is `{"file": null}`.

**Example `config.json`**:
```json
{
  "authorization": {"oauth_api":  "..."},
  "action": "search",
  "parameters": {
    "workbook": {
      "path": "https://.../sharing/link/from/OneDrive/...."
    }
  }
}
```

**Example result**:
```json
{
  "file": {
    "driveId":"...",
    "fileId":"...",
    "name":"one_sheet.xlsx",
    "path":"/path/to/folder"
  }
}
```

### Get Worksheets Action

Action `getWorksheets` serves to list all worksheets (tabs) from workbook `XSLX` file.

**Example `config.json`**:

Workbook configured by `driveId` and `fileId`

```json
{
  "authorization": {"oauth_api":  "..."},
  "action": "getWorksheets",
  "parameters": {
    "workbook": {
      "driveId": "...",
      "fileId": "..."
    }
  }
}
```

Workbook configured by `path`. If workbook is not found action results to `UserException`.

```json
{
  "authorization": {"oauth_api":  "..."},
  "action": "getWorksheets",
  "parameters": {
    "workbook": {
      "path": "site://Excel+Sheets/path/to/file.xlsx"
    }
  }
}
```


**Example result**:
```json
{
   "worksheets":[
      {
         "position":0,
         "name":"Hidden Sheet",
         "title":"Hidden Sheet (hidden)",
         "driveId":"...",
         "fileId":"...",
         "worksheetId":"...",
         "visible":false,
         "header":[
            "Col_1",
            "Col_2",
            "Col_3"
         ]
      }
   ]
}
```
## Development

For development it is necessary to:
  - Have an [Application in Microsoft identity platform](#application-in-microsoft-identity-platform)
    - Env variables: `OAUTH_APP_NAME`, `OAUTH_APP_ID`, `OAUTH_APP_SECRET`
    - You can use script to create app: `utils/oauth-app-setup.sh` 
    - Permissions (scopes) `offline_access User.Read Files.ReadWrite.All Sites.ReadWrite.All`.
  - Be logged in some OneDrive Business (Office 365) Account and have [OAuth tokens](#oauth-tokens)
    - Env variables: `OAUTH_ACCESS_TOKEN`, `OAUTH_REFRESH_TOKEN`, `TEST_SHAREPOINT_SITE`
    - To log in you can use script: `utils/oauth-login.sh` 

### Application in Microsoft identity platform 

- Component uses [Microsoft Graph API](https://developer.microsoft.com/en-us/graph) to connect to user's OneDrive.
- So for development you need access to some Microsoft application:
    - If you are Keboola employee, you can use existing app `wr-onedrive-dev-test`. Credentials are stored in [1Password](https://1password.com).
    - Or if you have work account on [portal.azure.com](https://portal.azure.com), you can create new app by `utils/oauth-app-setup.sh`
    - Or you can have personal account on [portal.azure.com](https://portal.azure.com). App can be created manually in `App registrations` section.
- To access all types of accounts (personal / work / school):
    - Property `signInAudience` must be set to `AzureADandPersonalMicrosoftAccount`. 
    - You can check it in Azure Portal, in app detail, in `Manifest` section.
- At least one `Redirect URIs` must be set:
    - Open `portal.azure.com` -> `App registrations` -> app-name -> `Authentication`
    - In `Web` -> `Redirect URIs` click `Add URI`
    - For development you should add `http://localhost:10000/sign-in/callback`.
    - Click `Save`
- If you have an application set, please store credentials in `.env` file.
```.env
OAUTH_APP_NAME=my-app-name
OAUTH_APP_ID=...
OAUTH_APP_SECRET=...
```

### OAuth tokens

- OAuth tokens are result of login to specific OneDrive account.
- OAuth login is not part of this repository. It is done in other parts of KBC, see [OAuth 2.0 Authentication](https://developers.keboola.com/extend/generic-extractor/configuration/api/authentication/oauth20/).
- Component uses the OAuth tokens to authorize to Graph API.
- The `access_token` and `refresh_token` are part of `config.json` in `authorization.oauth_api.credentials.#data`.
- Component uses `refresh_token` (expires in 90 days) to generate new `access_token` (expires in 1 hour).
- For development / tests you must obtain this token manually:
    1. Setup environment variables `OAUTH_APP_NAME`, `OAUTH_APP_ID`, `OAUTH_APP_SECRET`
        - If are present in `.env` file, the script loads them.
    2. Run script `utils/oauth-login.sh`
    3. Follow the instructions (open the URL and login)
    4. Save tokens to `.env` file
 
### Workspace setup

Clone this repository and init the workspace with following command:

```sh
git clone https://github.com/keboola/wr-onedrive
cd wr-onedrive
docker-compose build
docker-compose run --rm dev composer install --no-scripts
```

Create `.env` file with following variables (from the previous steps)
```env
OAUTH_APP_NAME=
OAUTH_APP_ID=
OAUTH_APP_SECRET=
OAUTH_ACCESS_TOKEN=
OAUTH_REFRESH_TOKEN=
TEST_SHAREPOINT_SITE=(optional)
```

Run the test suite using this command:

```sh
docker-compose run --rm dev composer tests
```
