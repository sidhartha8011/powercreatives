> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# Sora2 - Image to Video

> Transform images into dynamic videos powered by Sora-2-image-to-video's advanced AI model

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

````yaml market/sora2/sora-2-image-to-video.json post /api/v1/jobs/createTask
openapi: 3.0.0
info:
  title: Sora-2-image-to-video API
  description: kie.ai Sora-2-image-to-video API Documentation - Image to Video
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
      summary: Generate videos from images using sora-2-image-to-video
      operationId: sora-2-image-to-video
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
                    - sora-2-image-to-video
                  default: sora-2-image-to-video
                  description: |-
                    The model name to use for generation. Required field.

                    - Must be `sora-2-image-to-video` for this endpoint
                  example: sora-2-image-to-video
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
                progressCallBackUrl:
                  type: string
                  format: uri
                  description: >-
                    The URL to receive task progress updates. Optional.


                    - During task execution, the system will POST task progress
                    and status to this URL

                    - Your callback endpoint should accept POST requests with
                    JSON payload
                  example: https://your-domain.com/api/v1/jobs/progressCallBackUrl
                input:
                  type: object
                  description: Input parameters for the generation task
                  properties:
                    prompt:
                      description: >-
                        The text prompt describing the desired video motion (Max
                        length: 10000 characters)
                      type: string
                      maxLength: 10000
                      example: >-
                        A claymation conductor passionately leads a claymation
                        orchestra, while the entire group joyfully sings in
                        chorus the phrase: “Sora 2 is now available on Kie AI.
                    image_urls:
                      description: >-
                        URL of the image to use as the first frame. Must be
                        publicly accessible (File URL after upload, not file
                        content; Accepted types: image/jpeg, image/png,
                        image/webp; Max size: 10.0MB)
                      type: array
                      items:
                        type: string
                        format: uri
                      maxItems: 1
                      example:
                        - >-
                          https://file.aiquickdraw.com/custom-page/akr/section-images/17594315607644506ltpf.jpg
                    aspect_ratio:
                      description: This parameter defines the aspect ratio of the image.
                      type: string
                      enum:
                        - portrait
                        - landscape
                      default: landscape
                      example: landscape
                    n_frames:
                      description: The number of frames to be generated.
                      type: string
                      enum:
                        - '10'
                        - '15'
                      default: '10'
                      example: '10'
                    remove_watermark:
                      description: >-
                        When enabled, removes watermarks from the generated
                        video. (Boolean value (true/false))
                      type: boolean
                      example: true
                    upload_method:
                      type: string
                      enum:
                        - s3
                        - oss
                      default: s3
                      description: >-
                        Upload destination. Defaults to s3; choose oss for
                        Aliyun storage (better access within China).
                      example: s3
                  required:
                    - prompt
                    - image_urls
            example:
              model: sora-2-image-to-video
              callBackUrl: https://your-domain.com/api/callback
              progressCallBackUrl: https://your-domain.com/api/v1/jobs/progressCallBackUrl
              input:
                prompt: >-
                  A claymation conductor passionately leads a claymation
                  orchestra, while the entire group joyfully sings in chorus the
                  phrase: “Sora 2 is now available on Kie AI.
                image_urls:
                  - >-
                    https://file.aiquickdraw.com/custom-page/akr/section-images/17594315607644506ltpf.jpg
                aspect_ratio: landscape
                n_frames: '10'
                remove_watermark: true
                upload_method: s3
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
                            example: task_sora-2-image-to-video_1765184045509
              example:
                code: 200
                msg: success
                data:
                  taskId: task_sora-2-image-to-video_1765184045509
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