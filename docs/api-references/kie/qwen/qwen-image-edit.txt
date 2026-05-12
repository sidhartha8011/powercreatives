> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# Qwen - image-edit

> Image generation by qwen/image-edit

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

````yaml market/qwen/image-edit.json post /api/v1/jobs/createTask
openapi: 3.0.0
info:
  title: Qwen API
  description: kie.ai Qwen API Documentation
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
      summary: Generate content using qwen/image-edit
      operationId: qwen-image-edit
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
                    - qwen/image-edit
                  default: qwen/image-edit
                  description: |-
                    The model name to use for generation. Required field.

                    - Must be `qwen/image-edit` for this endpoint
                  example: qwen/image-edit
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
                        The prompt to generate the image with (Max length: 2000
                        characters)
                      type: string
                      maxLength: 2000
                      example: ''
                    image_url:
                      description: >-
                        The URL of the image to edit. (File URL after upload,
                        not file content; Accepted types: image/jpeg, image/png,
                        image/webp; Max size: 10.0MB)
                      type: string
                      example: >-
                        https://file.aiquickdraw.com/custom-page/akr/section-images/1755603225969i6j87xnw.jpg
                    acceleration:
                      description: >-
                        Acceleration level for image generation. Options:
                        'none', 'regular'. Higher acceleration increases speed.
                        'regular' balances speed and quality. Default value:
                        "none"
                      type: string
                      enum:
                        - none
                        - regular
                        - high
                      default: none
                      example: none
                    image_size:
                      description: >-
                        The size of the generated image. Default value:
                        landscape_4_3
                      type: string
                      enum:
                        - square
                        - square_hd
                        - portrait_4_3
                        - portrait_16_9
                        - landscape_4_3
                        - landscape_16_9
                      default: landscape_4_3
                      example: landscape_4_3
                    num_inference_steps:
                      description: >-
                        The number of inference steps to perform. Default value:
                        30 (Min: 2, Max: 49, Step: 1) (step: 1)
                      type: number
                      minimum: 2
                      maximum: 49
                      default: 25
                      example: 25
                    seed:
                      description: >-
                        The same seed and the same prompt given to the same
                        version of the model will output the same image every
                        time.
                      type: integer
                    guidance_scale:
                      description: >-
                        The CFG (Classifier Free Guidance) scale is a measure of
                        how close you want the model to stick to your prompt
                        when looking for a related image to show you. Default
                        value: 4 (Min: 0, Max: 20, Step: 0.1) (step: 0.1)
                      type: number
                      minimum: 0
                      maximum: 20
                      default: 4
                      example: 4
                    sync_mode:
                      description: >-
                        If set to true, the function will wait for the image to
                        be generated and uploaded before returning the response.
                        This will increase the latency of the function but it
                        allows you to get the image directly in the response
                        without going through the CDN. (Boolean value
                        (true/false))
                      type: boolean
                      example: false
                    num_images:
                      description: num_images
                      type: string
                      enum:
                        - '1'
                        - '2'
                        - '3'
                        - '4'
                    enable_safety_checker:
                      description: >-
                        If set to true, the safety checker will be enabled.
                        Default value: true (Boolean value (true/false))
                      type: boolean
                      example: true
                    output_format:
                      description: 'The format of the generated image. Default value: "png"'
                      type: string
                      enum:
                        - jpeg
                        - png
                      default: png
                      example: png
                    negative_prompt:
                      description: >-
                        The negative prompt for the generation Default value: "
                        " (Max length: 500 characters)
                      type: string
                      maxLength: 500
                      example: blurry, ugly
                  required:
                    - prompt
                    - image_url
            example:
              model: qwen/image-edit
              callBackUrl: https://your-domain.com/api/callback
              input:
                prompt: ''
                image_url: >-
                  https://file.aiquickdraw.com/custom-page/akr/section-images/1755603225969i6j87xnw.jpg
                acceleration: none
                image_size: landscape_4_3
                num_inference_steps: 25
                guidance_scale: 4
                sync_mode: false
                enable_safety_checker: true
                output_format: png
                negative_prompt: blurry, ugly
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
                            example: task_qwen_1765179676651
              example:
                code: 200
                msg: success
                data:
                  taskId: task_qwen_1765179676651
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