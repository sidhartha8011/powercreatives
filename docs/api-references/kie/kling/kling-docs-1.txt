> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# Grok Imagine - Text to Video

> High-quality video generation from text descriptions powered by Grok's advanced AI model

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

````yaml market/grok-imagine/text-to-video.json post /api/v1/jobs/createTask
openapi: 3.0.0
info:
  title: Grok Imagine API
  description: kie.ai Grok Imagine API Documentation - Text to Video Generation
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
      summary: Generate Grok Imagine Video
      description: >-
        Create a new Grok Imagine text-to-video generation task using the
        grok-imagine/text-to-video model.


        ### Important Notes

        - Generated videos are processed asynchronously

        - Use the returned taskId to track generation progress

        - Callback URL is recommended for production use to receive automatic
        notifications when generation completes

        - Generated video URLs are valid for 24 hours - download and store
        videos immediately

        - Supports multiple aspect ratios for flexible video composition

        - High-quality video generation with motion


        ### Generation Time

        Video generation typically takes:

        - **Standard quality**: 10-15 seconds

        - **High complexity prompts**: 15-25 seconds


        ### Rate Limits

        - Maximum 30 concurrent tasks per account

        - Maximum 300 task creations per hour

        - Single video per request


        ### Best Practices

        **Writing Effective Prompts:**

        - Be specific about motion, movement, and action sequences

        - Describe camera movements, transitions, and timing

        - Include visual details about subjects and environments

        - Specify the type of motion and pacing you want


        **Choosing Aspect Ratios:**

        - **2:3 (Portrait)**: Best for mobile videos, vertical social media

        - **3:2 (Landscape)**: Ideal for desktop viewing, presentations

        - **1:1 (Square)**: Perfect for Instagram Reels, TikTok, balanced
        compositions


        **Mode Selection:**

        - **fun**: More creative and playful interpretation

        - **normal**: Balanced approach with good motion quality

        - **spicy**: More dynamic and intense motion effects


        ### Common Issues

        **Video quality not as expected:** Try more specific motion descriptions
        and adjust mode setting

        **Generation taking too long:** Typical generation is 10-25 seconds;
        check task status or verify callback URL accessibility

        **Motion doesn't match prompt:** Be more explicit about movement
        directions, speeds, and transitions
      operationId: grok-imagine-text-to-video
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              properties:
                model:
                  type: string
                  enum:
                    - grok-imagine/text-to-video
                  default: grok-imagine/text-to-video
                  description: |-
                    The model name to use for generation. Required field.

                    - Must be `grok-imagine/text-to-video` for this endpoint
                  example: grok-imagine/text-to-video
                callBackUrl:
                  type: string
                  format: uri
                  description: >-
                    The URL to receive video generation task completion updates.
                    Optional but recommended for production use.


                    - System will POST task status and results to this URL when
                    video generation completes

                    - Callback includes generated video URLs and task
                    information

                    - Your callback endpoint should accept POST requests with
                    JSON payload containing video results

                    - Alternatively, use the Get Task Details endpoint to poll
                    task status

                    - To ensure callback security, see [Webhook Verification
                    Guide](/common-api/webhook-verification) for signature
                    verification implementation
                  example: https://your-domain.com/api/callback
                input:
                  type: object
                  description: Input parameters for the video generation task
                  properties:
                    prompt:
                      type: string
                      description: >-
                        Text prompt describing the desired video motion.
                        Required field.


                        - Should be detailed and specific about the desired
                        visual motion

                        - Describe movement, action sequences, camera work, and
                        timing

                        - Include details about subjects, environments, and
                        motion dynamics

                        - Maximum length: 5000 characters

                        - Supports English language prompts
                      example: >-
                        A couple of doors open to the right one by one randomly
                        and stay open, to show the inside, each is either a
                        living room, or a kitchen, or a bedroom or an office,
                        with little people living inside.
                    aspect_ratio:
                      type: string
                      description: >-
                        Specifies the width-to-height ratio of the generated
                        video. Controls the aspect ratio of the output.


                        - **2:3**: Portrait orientation (vertical)

                        - **3:2**: Landscape orientation (horizontal)

                        - **1:1**: Square format

                        - **16:9**: Wide screen format

                        - **9:16**: Tall screen format


                        Default: 2:3
                      enum:
                        - '2:3'
                        - '3:2'
                        - '1:1'
                        - '16:9'
                        - '9:16'
                      default: '2:3'
                      example: '2:3'
                    mode:
                      type: string
                      description: >-
                        Specifies the generation mode affecting the style and
                        intensity of motion.


                        - **fun**: More creative and playful interpretation

                        - **normal**: Balanced approach with good motion quality

                        - **spicy**: More dynamic and intense motion effects


                        Default: normal
                      enum:
                        - fun
                        - normal
                        - spicy
                      default: normal
                      example: normal
                    duration:
                      description: The duration of the generated video in seconds
                      type: string
                      enum:
                        - '6'
                        - '10'
                      default: '6'
                      example: '6'
                    resolution:
                      description: |-
                        The resolution of the generated video.
                        - **480p**: Standard definition quality (default)
                        - **720p**: High definition quality
                      type: string
                      enum:
                        - 480p
                        - 720p
                      default: 480p
                      example: 480p
                  required:
                    - prompt
              required:
                - model
                - input
              example:
                model: grok-imagine/text-to-video
                callBackUrl: https://your-domain.com/api/callback
                input:
                  prompt: >-
                    A couple of doors open to the right one by one randomly and
                    stay open, to show the inside, each is either a living room,
                    or a kitchen, or a bedroom or an office, with little people
                    living inside.
                  aspect_ratio: '2:3'
                  mode: normal
                  duration: '6'
                  resolution: 480p
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
                            example: task_grok_video_12345678
              example:
                code: 200
                msg: success
                data:
                  taskId: 281e5b0*********************f39b9
        '500':
          $ref: '#/components/responses/Error'
      callbacks:
        onVideoGenerated:
          '{$request.body#/callBackUrl}':
            post:
              summary: Video Generation Callback
              description: >-
                When the video generation task is completed, the system will
                send the result to your provided callback URL via POST request
              requestBody:
                required: true
                content:
                  application/json:
                    schema:
                      type: object
                      properties:
                        code:
                          type: integer
                          description: >-
                            Status code


                            - **200**: Success - Video generation completed
                            successfully

                            - **501**: Failed - Video generation task failed
                          enum:
                            - 200
                            - 501
                        msg:
                          type: string
                          description: Status message
                          example: Playground task completed successfully.
                        data:
                          type: object
                          properties:
                            taskId:
                              type: string
                              description: Task ID
                              example: 281e5b0*********************f39b9
                    example:
                      code: 200
                      msg: Playground task completed successfully.
                      data:
                        taskId: 281e5b0*********************f39b9
              responses:
                '200':
                  description: Callback received successfully
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

            - **501**: Generation Failed - Video generation task failed

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