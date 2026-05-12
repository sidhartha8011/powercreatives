> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# Qwen - Image to Image

> Image generation by Qwen's advanced AI model

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

````yaml market/qwen/image-to-image.json post /api/v1/jobs/createTask
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
      summary: Generate content using qwen/image-to-image
      operationId: qwen-image-to-image
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
                    - qwen/image-to-image
                  default: qwen/image-to-image
                  description: |-
                    The model name to use for generation. Required field.

                    - Must be `qwen/image-to-image` for this endpoint
                  example: qwen/image-to-image
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
                        The prompt to generate the image with (Max length: 5000
                        characters)
                      type: string
                      maxLength: 5000
                      example: ''
                    image_url:
                      description: >-
                        The reference image to guide the generation (File URL
                        after upload, not file content; Accepted types:
                        image/jpeg, image/png, image/webp; Max size: 10.0MB)
                      type: string
                      example: ''
                    strength:
                      description: >-
                        Denoising strength. 1.0 = fully remake; 0.0 = preserve
                        original (Min: 0, Max: 1, Step: 0.01) (step: 0.01)
                      type: number
                      minimum: 0
                      maximum: 1
                      default: 0.8
                      example: 0.8
                    output_format:
                      description: The format of the generated image
                      type: string
                      enum:
                        - png
                        - jpeg
                      default: png
                      example: png
                    acceleration:
                      description: >-
                        Acceleration level for image generation. Options:
                        'none', 'regular', 'high'. Higher acceleration increases
                        speed. 'regular' balances speed and quality. 'high' is
                        recommended for images without text
                      type: string
                      enum:
                        - none
                        - regular
                        - high
                      default: none
                      example: none
                    negative_prompt:
                      description: >-
                        The negative prompt for the generation (Max length: 500
                        characters)
                      type: string
                      maxLength: 500
                      example: blurry, ugly
                    seed:
                      description: >-
                        The same seed and the same prompt given to the same
                        version of the model will output the same image every
                        time
                      type: integer
                    num_inference_steps:
                      description: >-
                        The number of inference steps to perform (Min: 2, Max:
                        250, Step: 1) (step: 1)
                      type: number
                      minimum: 2
                      maximum: 250
                      default: 30
                      example: 30
                    guidance_scale:
                      description: >-
                        The CFG (Classifier Free Guidance) scale is a measure of
                        how close you want the model to stick to your prompt
                        when looking for a related image to show you (Min: 0,
                        Max: 20, Step: 0.1) (step: 0.1)
                      type: number
                      minimum: 0
                      maximum: 20
                      default: 2.5
                      example: 2.5
                    enable_safety_checker:
                      description: >-
                        The safety checker is always enabled in Playground. It
                        can only be disabled by setting false through the API.
                        (Boolean value (true/false))
                      type: boolean
                      example: true
                  required:
                    - prompt
                    - image_url
            example:
              model: qwen/image-to-image
              callBackUrl: https://your-domain.com/api/callback
              input:
                prompt: ''
                image_url: ''
                strength: 0.8
                output_format: png
                acceleration: none
                negative_prompt: blurry, ugly
                num_inference_steps: 30
                guidance_scale: 2.5
                enable_safety_checker: true
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
                            example: task_qwen_1765178156594
              example:
                code: 200
                msg: success
                data:
                  taskId: task_qwen_1765178156594
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