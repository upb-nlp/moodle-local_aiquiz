# AI Quiz generator (local_aiquiz)

Generate Moodle quizzes from your course materials with an AI service.

A teacher picks files already published in the course, chooses question types
(multiple choice, true/false, short answer), how many of each, the difficulty
split and the language. The plugin creates the quiz as a **hidden draft**, sends
the request to the generation service, and imports the questions it delivers.
Students see nothing until the teacher reviews the questions and clicks
**Publish**.

## Requirements

- Moodle 4.5 or later.
- Cron running: requests are sent by an adhoc task, not during the teacher's
  page load.
- Access to a compatible **quiz generation service** (an API endpoint and an
  authentication token). The plugin does not generate questions itself.
- The service must be able to reach your Moodle site over HTTPS, both to
  download the selected files and to deliver the finished questions.

## Installation

1. Download the ZIP and install it from *Site administration > Plugins >
   Install plugins*, or unzip it into `local/aiquiz` in your Moodle folder.
2. Visit *Site administration > Notifications* to finish the installation.
3. Configure the plugin (below).

## Configuration

*Site administration > Plugins > Local plugins > AI Quiz generator*

| Setting | Description |
|---|---|
| API base URL | Base address of the generation service, without the API path, e.g. `https://example.org/moodle`. |
| Authentication token | Token issued by the generation service. |
| Allow a local API address | Development only. Lets Moodle call a service on localhost or a private network. Leave off in production. |

The capability `local/aiquiz:generate` (editing teachers and managers by
default) controls who can generate quizzes.

## Usage

1. In a course, open *More > AI Quiz generator*.
2. Click **Generate new quiz**, select the source materials (File or Folder
   resources), the question types and counts, then submit.
3. The quiz appears in the chosen section as a hidden activity with the status
   *Generating*. When the service delivers the questions, the status changes
   to *Ready to review*.
4. Review the questions in the quiz, then click **Publish** to show it to
   students. **Unpublish** hides it again.

## How the generation service is called

The plugin POSTs a JSON job to `{API base URL}/question-bank/generation-jobs`
with:

- the request id and a callback URL on your site (`/local/aiquiz/callback.php`);
- the language, learning objectives and requested question types;
- for each selected file: its name, type and a download link.

The service answers immediately, fetches the files, and later POSTs the
generated questions to the callback URL.

## Privacy

The plugin stores one record per generation request (the requesting teacher,
course, quiz and settings). It sends the learning objectives and download links
for the selected course files to the configured external service. The download
links contain the requesting teacher's file access key. The plugin implements
the Moodle Privacy API, so this data appears in data requests and can be
deleted.

## License

GNU GPL v3 or later. See <https://www.gnu.org/licenses/>.
