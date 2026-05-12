> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# kling-2.6/motion-control

> Content generation using kling-2.6/motion-control

## File Upload Requirements

Before using the Motion Control API, you need to upload your image and video files:

<Steps>
  <Step title="Upload Reference Image">
    Use the File Upload API to upload your reference image showing the subject.

    <Card title="File Upload API" icon="upload" href="/file-upload-api/quickstart">
      Learn how to upload images and get file URLs
    </Card>

    **Requirements:**

    * **File Type**: JPEG, PNG, or JPG format
    * **Max File Size**: 10MB per file
    * **Content**: Clear image showing the subject's head, shoulders, and torso
  </Step>

  <Step title="Upload Motion Video">
    Upload a video that defines the motion pattern you want to apply.

    **Requirements:**

    * **File Type**: MP4, QuickTime, or Matroska format
    * **Duration**: Between 3-30 seconds per video
    * **Max File Size**: 100MB per file
    * **Content**: Video clearly showing the subject's head, shoulders, and torso
  </Step>

  <Step title="Get File URLs">
    After upload, you'll receive file URLs that you can use in the `input_urls` and `video_urls` parameters.
  </Step>
</Steps>

<Warning>
  * Supported image formats: JPEG, PNG, JPG (Max: 10MB)
  * Supported video formats: MP4, QuickTime, Matroska (Max: 100MB, 3-30 seconds)
  * Videos must clearly show the subject's head, shoulders, and torso
  * Maximum one image and one video per request
</Warning>

## Query Task Status

After submitting a task, use the unified query endpoint to check progress and retrieve results:

<Card title="Get Task Details" icon="magnifying-glass" href="/market/common/get-task-detail">
  Learn how to query task status and retrieve generation results
</Card>

<Tip>
  For production use, we recommend using the `callBackUrl` parameter to receive automatic notifications when generation completes, rather than polling the status endpoint.
</Tip>

## Related Resources

<CardGroup cols={2}>
  <Card title="Market Overview" icon="store" href="/market/quickstart">
    Explore all available models
  </Card>

  <Card title="Common API" icon="gear" href="/common-api/get-account-credits">
    Check credits and account usage
  </Card>
</CardGroup>


## OpenAPI

````yaml market/kling/motion-control.json post /api/v1/jobs/createTask
openapi: 3.0.0
info:
  title: Kling-2.6 API
  description: kie.ai Kling-2.6 API Documentation - undefined
  version: 1.0.0
  contact:
    name: Technical Support
    email: support@kie.ai
servers:
  - url: https://api.kie.ai
    description: API Server
security:
  - BearerAuth: []
paths:
  /api/v1/jobs/createTask:
    post:
      summary: Generate content using kling-2.6/motion-control
      operationId: kling-2-6-motion-control
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required:
                - model
              properties:
                model:
                  type: string
                  enum:
                    - kling-2.6/motion-control
                  default: kling-2.6/motion-control
                  description: |-
                    The model name to use for generation. Required field.

                    - Must be `kling-2.6/motion-control` for this endpoint
                  example: kling-2.6/motion-control
                callBackUrl:
                  type: string
                  format: uri
                  description: >-
                    The URL to receive generation task completion updates.
                    Optional but recommended for production use.


                    - System will POST task status and results to this URL when
                    generation completes

                    - Callback includes generated content URLs and task
                    information

                    - Your callback endpoint should accept POST requests with
                    JSON payload containing results

                    - Alternatively, use the Get Task Details endpoint to poll
                    task status

                    - To ensure callback security, see [Webhook Verification
                    Guide](/common-api/webhook-verification) for signature
                    verification implementation
                  example: https://your-domain.com/api/callback
                input:
                  type: object
                  description: Input parameters for the generation task
                  properties:
                    prompt:
                      description: >-
                        A text description of the desired output. Maximum length
                        is 2500 characters. (Max length: 2500 characters)
                      type: string
                      maxLength: 2500
                      example: The cartoon character is dancing.
                    input_urls:
                      description: >-
                        An array containing a single image URL. The photo must
                        clearly show the subject's head, shoulders, and torso.
                        (File URL after upload, not file content; Accepted
                        types: image/jpeg, image/png, image/jpg; Max size:
                        10.0MB,size needs to be greater than 300px, aspect ratio
                        2:5 to 5:2.)
                      type: array
                      items:
                        type: string
                        format: uri
                      maxItems: 1
                      example:
                        - >-
                          https://static.aiquickdraw.com/tools/example/1767694885407_pObJoMcy.png
                    video_urls:
                      description: >-
                        An array containing a single video URL. The duration
                        must be between 3 to 30 seconds, and the video must
                        clearly show the subject's head, shoulders, and torso.
                        (File URL after upload, not file content; Accepted
                        types: video/mp4, video/quicktime, video/x-matroska; Max
                        size: 100.0MB)
                      type: array
                      items:
                        type: string
                        format: uri
                      maxItems: 1
                      example:
                        - >-
                          https://static.aiquickdraw.com/tools/example/1767525918769_QyvTNib2.mp4
                    character_orientation:
                      description: >-
                        Generate the orientation of the characters in the video.
                        'image': same orientation as the person in the picture
                        (max 10s video). 'video': consistent with the
                        orientation of the characters in the video (max 30s
                        video).
                      type: string
                      enum:
                        - image
                        - video
                      default: video
                      example: video
                    mode:
                      description: >-
                        Output resolution mode. Use 'std' for 720p or 'pro' for
                        1080p.
                      type: string
                      enum:
                        - 720p
                        - 1080p
                      default: 720p
                      example: 720p
                  required:
                    - input_urls
                    - video_urls
                    - mode
                    - character_orientation
            example:
              model: kling-2.6/motion-control
              callBackUrl: https://your-domain.com/api/callback
              input:
                prompt: The cartoon character is dancing.
                input_urls:
                  - >-
                    https://static.aiquickdraw.com/tools/example/1767694885407_pObJoMcy.png
                video_urls:
                  - >-
                    https://static.aiquickdraw.com/tools/example/1767525918769_QyvTNib2.mp4
                mode: 720p
                character_orientation: image
      responses:
        '200':
          description: Request successful
          content:
            application/json:
              schema:
                allOf:
                  - $ref: '#/components/schemas/ApiResponse'
                  - type: object
                    properties:
                      data:
                        type: object
                        properties:
                          taskId:
                            type: string
                            description: >-
                              Task ID, can be used with Get Task Details
                              endpoint to query task status
                            example: task_kling-2.6_1767693973938
              example:
                code: 200
                msg: success
                data:
                  taskId: task_kling-2.6_1767693973938
        '500':
          $ref: '#/components/responses/Error'
components:
  schemas:
    ApiResponse:
      type: object
      properties:
        code:
          type: integer
          enum:
            - 200
            - 401
            - 402
            - 404
            - 422
            - 429
            - 455
            - 500
            - 501
            - 505
          description: >-
            Response status code


            - **200**: Success - Request has been processed successfully

            - **401**: Unauthorized - Authentication credentials are missing or
            invalid

            - **402**: Insufficient Credits - Account does not have enough
            credits to perform the operation

            - **404**: Not Found - The requested resource or endpoint does not
            exist

            - **422**: Validation Error - The request parameters failed
            validation checks

            - **429**: Rate Limited - Request limit has been exceeded for this
            resource

            - **455**: Service Unavailable - System is currently undergoing
            maintenance

            - **500**: Server Error - An unexpected error occurred while
            processing the request

            - **501**: Generation Failed - Content generation task failed

            - **505**: Feature Disabled - The requested feature is currently
            disabled
        msg:
          type: string
          description: Response message, error description when failed
          example: success
  responses:
    Error:
      description: Server Error
  securitySchemes:
    BearerAuth:
      type: http
      scheme: bearer
      bearerFormat: API Key
      description: >-
        All APIs require authentication via Bearer Token.


        Get API Key:

        1. Visit [API Key Management Page](https://kie.ai/api-key) to get your
        API Key


        Usage:

        Add to request header:

        Authorization: Bearer YOUR_API_KEY


        Note:

        - Keep your API Key secure and do not share it with others

        - If you suspect your API Key has been compromised, reset it immediately
        in the management page

````